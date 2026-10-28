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

use Bitlane\OpenCart\Support\BitlaneError;
use Bitlane\OpenCart\Support\Http;
use Bitlane\OpenCart\Support\Lock;
use Bitlane\OpenCart\Support\State;
use Bitlane\OpenCart\Support\Translator;

/**
 * Bitlane's OAuth 2.1 flow for a confidential client (docs/oauth-plan.md,
 * docs/opencart-plan.md OC2): the extension registers itself (Dynamic Client
 * Registration) the first time, opens Bitlane's consent page in a popup with
 * PKCE, and exchanges the code from PHP, so the store doesn't have to be
 * reachable from the internet. The redirect comes back to the storefront,
 * not the admin: OpenCart's admin cookie is SameSite=Strict, so it never
 * comes along on a redirect from bitlane.co, and a fixed redirect URI can't
 * carry the admin's user_token. The state, made for one admin user and used
 * once within 15 minutes, is the callback's check. Refreshes run one at a
 * time, since several requests can find the token expired together.
 */
final class OAuthClient {
	public const SCOPES = 'projects:read engines:write widgets:write analytics:read offline_access';

	/** How long a Connect waits for Bitlane's answer. */
	private const PENDING = 900;

	/**
	 * @param string $redirectUri the storefront's callback (store 0)
	 * @param string $siteUrl store 0's address, the client's homepage when it's https
	 */
	public function __construct(
		private ConnectionStore $connection,
		private State $state,
		private Http $http,
		private Lock $lock,
		private Translator $translator,
		private string $version,
		private string $redirectUri,
		private string $siteUrl,
	) {
	}

	/**
	 * The API, https://api.bitlane.co unless BITLANE_API_URL (config.php, or
	 * the environment) says otherwise.
	 */
	public function apiUrl(): string {
		$url = defined('BITLANE_API_URL') ? (string) constant('BITLANE_API_URL') : (string) getenv('BITLANE_API_URL');

		return rtrim($url !== '' ? $url : 'https://api.bitlane.co', '/');
	}

	/**
	 * The authorization server's metadata (RFC 8414), kept a day.
	 *
	 * @return array<string, mixed>
	 */
	public function metadata(): array {
		$cached = $this->state->json('oauth_metadata');
		if (($cached['url'] ?? '') === $this->apiUrl() && ($cached['at'] ?? 0) > time() - 86400 && is_array($cached['data'] ?? null)) {
			return $cached['data'];
		}
		$res = $this->http->request('GET', $this->apiUrl() . '/.well-known/oauth-authorization-server');
		if ($res['status'] !== 200 || empty($res['body']['authorization_endpoint'])) {
			throw new BitlaneError('metadata', $this->translator->t('Bitlane could not be reached. Try again in a moment.'), $res['status']);
		}
		$this->state->setJson('oauth_metadata', ['url' => $this->apiUrl(), 'at' => time(), 'data' => $res['body']]);

		return $res['body'];
	}

	/**
	 * The dashboard's address, for "Open in Bitlane": the consent page's host.
	 */
	public function appUrl(): ?string {
		try {
			$parts = parse_url((string) $this->metadata()['authorization_endpoint']);
		} catch (BitlaneError) {
			return null;
		}
		if (!isset($parts['scheme'], $parts['host'])) {
			return null;
		}

		return $parts['scheme'] . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '');
	}

	/**
	 * The consent page's URL for a new grant, registering the client first
	 * when needed. The PKCE verifier, the expected issuer, the admin user,
	 * their language and the store wait in the state under the request's
	 * state.
	 */
	public function authorizeUrl(int $userId, int $storeId): string {
		$metadata = $this->metadata();
		$client = $this->client($metadata);

		$verifier = $this->random();
		$state = $this->random();
		$this->state->prune('oauth:', time() - self::PENDING);
		$this->state->setJson('oauth:' . hash('sha256', $state), [
			'verifier' => $verifier,
			'issuer' => (string) ($metadata['issuer'] ?? ''),
			'user' => $userId,
			'store' => $storeId,
			'language' => $this->translator->code(),
		]);

		return $metadata['authorization_endpoint'] . '?' . http_build_query([
			'response_type' => 'code',
			'client_id' => $client['client_id'],
			'redirect_uri' => $client['redirect_uri'],
			'scope' => self::SCOPES,
			'state' => $state,
			'code_challenge' => $this->base64url(hash('sha256', $verifier, true)),
			'code_challenge_method' => 'S256',
			'resource' => $this->apiUrl(),
		], '', '&', PHP_QUERY_RFC3986);
	}

	/**
	 * What a Connect left waiting under $state; empty when there's none (or
	 * it expired). The callback reads the admin's language from it first.
	 *
	 * @return array<string, mixed>
	 */
	public function pending(string $state): array {
		if ($state === '') {
			return [];
		}
		$this->state->prune('oauth:', time() - self::PENDING);

		return $this->state->json('oauth:' . hash('sha256', $state));
	}

	/**
	 * Handles Bitlane's answer on the redirect URI: checks the state (one of
	 * ours, not expired, used once) and the issuer (RFC 9207), then exchanges
	 * the code.
	 *
	 * @param array<string, string> $query the callback's query
	 *
	 * @return array{user: int, store: int} who clicked Connect, and in which store
	 */
	public function handleCallback(array $query): array {
		$pending = $this->pending($query['state'] ?? '');
		if (!$pending) {
			throw new BitlaneError('state', $this->translator->t('The connection request expired or came from elsewhere. Click Connect again.'));
		}
		$this->state->set('oauth:' . hash('sha256', $query['state'] ?? ''), null);

		if (isset($query['error'])) {
			throw $query['error'] === 'access_denied'
				? new BitlaneError('access_denied', $this->translator->t('The connection was declined in Bitlane.'))
				: new BitlaneError($query['error'], (string) ($query['error_description'] ?? $query['error']));
		}
		if (isset($query['iss']) && $query['iss'] !== $pending['issuer']) {
			throw new BitlaneError('issuer', $this->translator->t('The answer did not come from Bitlane.'));
		}
		if (empty($query['code'])) {
			throw new BitlaneError('no_code', $this->translator->t('Bitlane sent no authorization code.'));
		}

		$tokens = $this->token([
			'grant_type' => 'authorization_code',
			'code' => $query['code'],
			'redirect_uri' => (string) ($this->connection->get()['redirect_uri'] ?? $this->redirectUri),
			'code_verifier' => (string) $pending['verifier'],
			'resource' => $this->apiUrl(),
		]);
		$this->saveTokens($tokens, ['project_id' => $tokens['project_id'] ?? null]);

		return ['user' => (int) $pending['user'], 'store' => (int) $pending['store']];
	}

	/**
	 * A valid access token, refreshed when it's about to expire.
	 */
	public function accessToken(): string {
		$connection = $this->connection->get();
		if (empty($connection['refresh_token'])) {
			throw new BitlaneError('disconnected', $this->translator->t('This store isn’t connected to Bitlane.'), 409);
		}
		if (!empty($connection['access_token']) && (int) ($connection['expires_at'] ?? 0) > time() + 60) {
			return (string) $connection['access_token'];
		}

		return $this->refresh((string) ($connection['access_token'] ?? ''));
	}

	/**
	 * Refreshes the tokens, unless another request did since $stale was read.
	 * A refused refresh token ends the connection.
	 *
	 * @param string $stale the access token found expired or refused
	 */
	public function refresh(string $stale): string {
		return $this->lock->with('refresh', function () use ($stale): string {
			$connection = $this->connection->get();
			if (empty($connection['refresh_token'])) {
				throw new BitlaneError('disconnected', $this->translator->t('This store isn’t connected to Bitlane.'), 409);
			}
			$renewed = !empty($connection['access_token']) && $connection['access_token'] !== $stale;
			if ($renewed && (int) ($connection['expires_at'] ?? 0) > time() + 60) {
				return (string) $connection['access_token'];
			}

			try {
				$tokens = $this->token([
					'grant_type' => 'refresh_token',
					'refresh_token' => (string) $connection['refresh_token'],
					'resource' => $this->apiUrl(),
				]);
			} catch (BitlaneError $error) {
				if ($error->kind === 'invalid_grant') {
					$this->connection->end(true);
				}
				throw $error;
			}

			return $this->saveTokens($tokens);
		});
	}

	/**
	 * Revokes the grant's refresh token (RFC 7009); the connection ends either way.
	 */
	public function revoke(): void {
		$connection = $this->connection->get();
		try {
			$metadata = $this->metadata();
			if (!empty($connection['refresh_token']) && !empty($metadata['revocation_endpoint'])) {
				$this->http->request('POST', (string) $metadata['revocation_endpoint'], [
					'headers' => $this->clientAuth($connection),
					'form' => ['token' => (string) $connection['refresh_token'], 'token_type_hint' => 'refresh_token'],
				]);
			}
		} catch (BitlaneError) {
			// Bitlane can't be reached: the store forgets the grant anyway.
		}
		$this->connection->end(false);
	}

	/**
	 * The registered client, registering it when there's none for this
	 * redirect URI (a new install, or the store moved).
	 *
	 * @param array<string, mixed> $metadata
	 *
	 * @return array<string, mixed>
	 */
	private function client(array $metadata): array {
		$connection = $this->connection->get();
		if (!empty($connection['client_id']) && $this->redirectUri === ($connection['redirect_uri'] ?? '')) {
			return $connection;
		}
		if (empty($metadata['registration_endpoint'])) {
			throw new BitlaneError('register', $this->translator->t('Bitlane does not accept new connections right now.'));
		}

		$client = [
			'redirect_uris' => [$this->redirectUri],
			'token_endpoint_auth_method' => 'client_secret_basic',
			'grant_types' => ['authorization_code', 'refresh_token'],
			'response_types' => ['code'],
			'client_name' => $this->translator->t('Bitlane for OpenCart (%host%)', ['host' => (string) parse_url($this->redirectUri, PHP_URL_HOST)]),
			'software_id' => 'bitlane-opencart',
			'software_version' => $this->version,
		];
		// Bitlane takes an https homepage only; a store on plain http goes without.
		if (str_starts_with($this->siteUrl, 'https://')) {
			$client['client_uri'] = $this->siteUrl;
		}
		$res = $this->http->request('POST', (string) $metadata['registration_endpoint'], ['json' => $client]);
		if (!in_array($res['status'], [200, 201], true) || empty($res['body']['client_id'])) {
			throw new BitlaneError('register', (string) ($res['body']['error_description'] ?? $this->translator->t('Bitlane refused to register this store.')), $res['status']);
		}

		return $this->connection->update([
			'client_id' => $res['body']['client_id'],
			'client_secret' => $res['body']['client_secret'] ?? '',
			'redirect_uri' => $this->redirectUri,
		]);
	}

	/**
	 * Calls the token endpoint with the client's credentials; a refusal
	 * throws with the OAuth error code as its kind.
	 *
	 * @param array<string, string> $params
	 *
	 * @return array<string, mixed>
	 */
	private function token(array $params): array {
		$res = $this->http->request('POST', (string) $this->metadata()['token_endpoint'], [
			'headers' => $this->clientAuth($this->connection->get()),
			'form' => $params,
		]);
		if ($res['status'] !== 200 || empty($res['body']['access_token'])) {
			throw new BitlaneError((string) ($res['body']['error'] ?? 'token'), (string) ($res['body']['error_description'] ?? $this->translator->t('Bitlane refused the connection.')), $res['status']);
		}

		return $res['body'];
	}

	/**
	 * Stores a token answer and returns the access token.
	 *
	 * @param array<string, mixed> $tokens
	 * @param array<string, mixed> $extra
	 */
	private function saveTokens(array $tokens, array $extra = []): string {
		$connection = $this->connection->update(array_merge([
			'access_token' => $tokens['access_token'],
			// A refresh without rotation keeps the one it has.
			'refresh_token' => $tokens['refresh_token'] ?? $this->connection->get()['refresh_token'] ?? null,
			'expires_at' => time() + (int) ($tokens['expires_in'] ?? 3600),
			'scope' => $tokens['scope'] ?? self::SCOPES,
			'revoked' => null,
		], $extra));

		return (string) $connection['access_token'];
	}

	/**
	 * HTTP Basic authentication with the client's id and secret (RFC 6749 2.3.1).
	 *
	 * @param array<string, mixed> $connection
	 *
	 * @return array<string, string>
	 */
	private function clientAuth(array $connection): array {
		$credentials = rawurlencode((string) ($connection['client_id'] ?? '')) . ':' . rawurlencode((string) ($connection['client_secret'] ?? ''));

		return ['Authorization' => 'Basic ' . base64_encode($credentials)];
	}

	private function random(): string {
		return $this->base64url(random_bytes(32));
	}

	private function base64url(string $bytes): string {
		return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
	}
}
