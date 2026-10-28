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

namespace Bitlane\OpenCart\Connection;

use Bitlane\OpenCart\Support\Crypto;
use Bitlane\OpenCart\Support\State;

/**
 * The connection to Bitlane. The install's, encrypted (docs/opencart-plan.md
 * OC4):
 * - client_id, client_secret, redirect_uri: the extension's OAuth client,
 *   registered with Bitlane on the first Connect;
 * - access_token, refresh_token, expires_at, scope: the grant's tokens;
 * - project_id: the project the grant is for (null: all the user's);
 * - revoked: Bitlane ended the grant (the app was revoked there).
 * And each store's integration id: the store in Bitlane.
 */
final class ConnectionStore {
	private const NAME = 'connection';
	private const INTEGRATION = 'integration';

	public function __construct(private State $state, private Crypto $crypto) {
	}

	/**
	 * The stored connection, empty when there's none or it can't be read.
	 *
	 * @return array<string, mixed>
	 */
	public function get(): array {
		$stored = $this->state->get(self::NAME);
		$json = $stored === null ? null : $this->crypto->decrypt($stored);
		$data = $json === null ? null : json_decode($json, true);

		return is_array($data) ? $data : [];
	}

	/**
	 * Merges changes into the connection; a null value removes the key.
	 *
	 * @param array<string, mixed> $changes
	 *
	 * @return array<string, mixed> the connection
	 */
	public function update(array $changes): array {
		$data = array_filter(array_merge($this->get(), $changes), static fn ($value) => $value !== null);
		$this->state->set(self::NAME, $this->crypto->encrypt((string) json_encode($data)));

		return $data;
	}

	/**
	 * Ends the grant and every store's place in Bitlane; the OAuth client
	 * stays, for the next Connect.
	 *
	 * @param bool $revoked whether Bitlane ended it (the admin is told)
	 */
	public function end(bool $revoked): void {
		$this->update([
			'access_token' => null,
			'refresh_token' => null,
			'expires_at' => null,
			'scope' => null,
			'project_id' => null,
			'revoked' => $revoked ? true : null,
		]);
		$this->state->forget(self::INTEGRATION);
	}

	public function connected(): bool {
		return !empty($this->get()['refresh_token']);
	}

	/**
	 * The store's integration in Bitlane; null before it's connected.
	 */
	public function integrationId(int $storeId): ?string {
		return $this->state->get(self::INTEGRATION, $storeId);
	}

	public function setIntegrationId(int $storeId, ?string $id): void {
		$this->state->set(self::INTEGRATION, $id, $storeId);
	}
}
