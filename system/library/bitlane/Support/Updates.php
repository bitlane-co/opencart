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
 * New versions of the extension (docs/opencart-plan.md OC20). OpenCart has
 * no update source for extensions outside its marketplace: the extension
 * reads the info.json each release puts on the CDN (URL, or
 * BITLANE_UPDATES_URL in config.php) at most once a day, when the Bitlane
 * screen loads, and the screen tells the merchant how to update.
 */
final class Updates {
	/** The environment's info.json: releases write it here (.github/deploy/opencart.sh). */
	public const URL = 'https://cdn.bitlane.co/opencart/info.json';

	public function __construct(private State $state, private Http $http, private string $version) {
	}

	/**
	 * The newer version and its zip; null when this one is the latest or
	 * the CDN can't be read.
	 *
	 * @return array{version: string, package: string}|null
	 */
	public function available(): ?array {
		// The repository's own (dev/opencart links it into the local stores).
		if ($this->version === '0.0.0') {
			return null;
		}
		$kept = $this->state->json('update');
		if ((int) ($kept['at'] ?? 0) < time() - 86400) {
			$kept = ['at' => time()] + $this->latest();
			$this->state->setJson('update', $kept);
		}
		$version = (string) ($kept['version'] ?? '');
		$package = (string) ($kept['package'] ?? '');

		return $version !== '' && $package !== '' && version_compare($version, $this->version, '>')
			? ['version' => $version, 'package' => $package]
			: null;
	}

	/**
	 * @return array{version?: string, package?: string}
	 */
	private function latest(): array {
		$url = defined('BITLANE_UPDATES_URL') ? (string) constant('BITLANE_UPDATES_URL') : self::URL;
		try {
			$res = $this->http->request('GET', $url);
		} catch (BitlaneError) {
			return [];
		}
		$body = $res['body'];
		if ($res['status'] !== 200 || !preg_match('/^\d+\.\d+\.\d+$/', (string) ($body['version'] ?? '')) || !preg_match('#^https://#', (string) ($body['package'] ?? ''))) {
			return [];
		}

		return ['version' => (string) $body['version'], 'package' => (string) $body['package']];
	}
}
