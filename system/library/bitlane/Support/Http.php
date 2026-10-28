<?php

/**
 * Bitlane Search for OpenCart
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to a commercial license from NMC, in the file
 * LICENSE.md that comes with it. Use, copy, modification or distribution of
 * this file or its parts without NMC's written permission is not allowed.
 *
 * @author    Bitlane <https://bitlane.co>
 * @copyright Since 2026 NMC
 * @license   Commercial license, see LICENSE.md
 */

declare(strict_types=1);

namespace Bitlane\OpenCart\Support;

/**
 * JSON over HTTP with cURL, no HTTP library (docs/opencart-plan.md OC19). A
 * transport failure throws; any HTTP status comes back with its body for the
 * caller to judge.
 *
 * BITLANE_CA_FILE (config.php, written by dev/opencart/install.sh) is the CA
 * bundle for .test hosts: the local stack's certificates come from Herd's CA.
 */
class Http {
	/**
	 * @param array{headers?: array<string, string>, json?: mixed, form?: array<string, string>} $options
	 *
	 * @return array{status: int, body: array<string, mixed>, retry_after: int}
	 */
	public function request(string $method, string $url, array $options = []): array {
		$headers = array_merge(['Accept' => 'application/json'], $options['headers'] ?? []);
		$body = null;
		if (array_key_exists('json', $options)) {
			$headers['Content-Type'] = 'application/json';
			$body = (string) json_encode($options['json']);
		} elseif (isset($options['form'])) {
			$headers['Content-Type'] = 'application/x-www-form-urlencoded';
			$body = http_build_query($options['form'], '', '&', PHP_QUERY_RFC3986);
		}

		$retryAfter = 0;
		$curl = curl_init($url);
		curl_setopt_array($curl, [
			CURLOPT_CUSTOMREQUEST => $method,
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_TIMEOUT => 20,
			CURLOPT_CONNECTTIMEOUT => 10,
			CURLOPT_HTTPHEADER => array_map(static fn ($name, $value) => $name . ': ' . $value, array_keys($headers), $headers),
			CURLOPT_HEADERFUNCTION => static function ($curl, string $line) use (&$retryAfter): int {
				if (stripos($line, 'retry-after:') === 0) {
					$retryAfter = (int) trim(substr($line, 12));
				}

				return strlen($line);
			},
		]);
		if ($body !== null) {
			curl_setopt($curl, CURLOPT_POSTFIELDS, $body);
		}
		if (defined('BITLANE_CA_FILE') && str_ends_with((string) parse_url($url, PHP_URL_HOST), '.test')) {
			curl_setopt($curl, CURLOPT_CAINFO, (string) constant('BITLANE_CA_FILE'));
		}

		$response = curl_exec($curl);
		$status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
		$error = curl_error($curl);
		if (!is_string($response)) {
			throw new BitlaneError('http_error', $error !== '' ? $error : 'The request failed.');
		}

		$decoded = json_decode($response, true);

		return ['status' => $status, 'body' => is_array($decoded) ? $decoded : [], 'retry_after' => $retryAfter];
	}
}
