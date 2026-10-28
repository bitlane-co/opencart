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

namespace Bitlane\OpenCart\Sync;

use Bitlane\OpenCart\Connection\BitlaneApi;
use Bitlane\OpenCart\Connection\ConnectionStore;
use Bitlane\OpenCart\Support\BitlaneError;
use Bitlane\OpenCart\Support\Crypto;
use Bitlane\OpenCart\Support\State;

/**
 * Where a store's documents go: Bitlane's GET /api/integrations/:id/credentials,
 * each engine's address and a write key limited to the store's indexes (a
 * week long, renewed with two days left), kept encrypted for an hour per store.
 */
final class Credentials {
	private const NAME = 'credentials';

	public function __construct(
		private BitlaneApi $api,
		private ConnectionStore $connection,
		private State $state,
		private Crypto $crypto,
	) {
	}

	/**
	 * Where a source's documents in a language go: its own index. An index the
	 * kept credentials don't have yet (a new setup made it) is looked for again
	 * in fresh ones.
	 *
	 * @param string $language as the setup sent it ("en-GB")
	 *
	 * @return array{url: string, key: string, index: string}
	 */
	public function index(int $storeId, string $source, string $language): array {
		foreach ([false, true] as $fresh) {
			if ($fresh) {
				$this->forget($storeId);
			}
			foreach ($this->get($storeId)['engines'] as $engine) {
				foreach ((array) ($engine['indexes'] ?? []) as $index) {
					if (($index['source'] ?? '') === $source && strtolower((string) ($index['language'] ?? '')) === strtolower($language)) {
						return ['url' => rtrim((string) $engine['engine_url'], '/'), 'key' => (string) $engine['write_key'], 'index' => (string) $index['uid']];
					}
				}
			}
		}
		throw new BitlaneError('no_index', sprintf('Bitlane has no index for %s in %s for this store yet.', $source, $language), 404);
	}

	/**
	 * The credentials, kept or from Bitlane.
	 *
	 * @return array{index_prefix: string, engines: list<array<string, mixed>>}
	 */
	public function get(int $storeId): array {
		$kept = $this->state->get(self::NAME, $storeId);
		$json = $kept === null ? null : $this->crypto->decrypt($kept);
		$data = $json === null ? null : json_decode($json, true);
		if (is_array($data) && ($data['at'] ?? 0) > time() - 3600) {
			return ['index_prefix' => (string) $data['index_prefix'], 'engines' => array_values((array) $data['engines'])];
		}
		$id = $this->connection->integrationId($storeId);
		if (!$id) {
			throw new BitlaneError('not_connected', 'This store isn’t connected to Bitlane.', 409);
		}
		$answer = $this->api->request('GET', '/api/integrations/' . rawurlencode($id) . '/credentials');
		$data = ['index_prefix' => (string) ($answer['index_prefix'] ?? ''), 'engines' => array_values((array) ($answer['engines'] ?? []))];
		$this->state->set(self::NAME, $this->crypto->encrypt((string) json_encode($data + ['at' => time()])), $storeId);

		return $data;
	}

	/**
	 * Drops the kept credentials: a refused key, a new setup, a disconnect.
	 */
	public function forget(int $storeId): void {
		$this->state->set(self::NAME, null, $storeId);
	}
}
