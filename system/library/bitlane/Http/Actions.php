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

namespace Bitlane\OpenCart\Http;

use Bitlane\OpenCart\Connection\BitlaneApi;
use Bitlane\OpenCart\Connection\ConnectionStore;
use Bitlane\OpenCart\Connection\OAuthClient;
use Bitlane\OpenCart\Platform\Version;
use Bitlane\OpenCart\Plugin;
use Bitlane\OpenCart\Queue\Jobs;
use Bitlane\OpenCart\Queue\Runner;
use Bitlane\OpenCart\Site\Settings;
use Bitlane\OpenCart\Site\Stores;
use Bitlane\OpenCart\Support\BitlaneError;
use Bitlane\OpenCart\Support\State;
use Bitlane\OpenCart\Support\Translator;

/**
 * What the Bitlane screen asks for (the admin controller's JSON actions), for
 * one store: the grant is the install's, each store is a site of its own in
 * Bitlane (docs/opencart-plan.md OC4). Tokens and keys never leave the
 * server.
 */
final class Actions {
	public function __construct(
		private Plugin $plugin,
		private ConnectionStore $connection,
		private OAuthClient $oauth,
		private BitlaneApi $api,
		private Stores $stores,
		private State $state,
		private Translator $translator,
		private Version $version,
		private Jobs $jobs,
		private Runner $runner,
	) {
	}

	/**
	 * Everything the screen shows for a store; without one (an install with
	 * several stores, none picked yet), the stores to pick from.
	 *
	 * @return array<string, mixed>
	 */
	public function status(?int $storeId, int $userId): array {
		$connection = $this->connection->get();
		$integration = null;
		$error = null;

		$integrationId = $storeId !== null ? $this->connection->integrationId($storeId) : null;
		if (!empty($connection['refresh_token']) && $integrationId) {
			try {
				$integration = $this->api->request('GET', '/api/integrations/' . rawurlencode($integrationId));
			} catch (BitlaneError $e) {
				if ($e->status === 404) {
					// The store belongs to another connection now: it connects again.
					$this->connection->setIntegrationId((int) $storeId, null);
				} else {
					$error = $e->getMessage();
				}
			}
			// A refused token may have ended the connection meanwhile.
			$connection = $this->connection->get();
			$integrationId = $this->connection->integrationId((int) $storeId);
		}
		$connected = !empty($connection['refresh_token']);
		$ready = $connected && $storeId !== null && ($integration['setup_state'] ?? '') === 'ready';
		if ($ready) {
			// The sources the setup changed sync in full (Sync::ready() knows which).
			$this->plugin->sync()->ready((int) $storeId, (string) ($integration['engine_id'] ?? ''));
			$this->plugin->storefront()->remember((int) $storeId, (array) $integration);
		}

		return [
			'connected' => $connected,
			'revoked' => !empty($connection['revoked']),
			'needs_project' => $connected && $storeId !== null && !$integrationId,
			'integration' => $integration,
			'error' => $error,
			'app_url' => $connected ? $this->oauth->appUrl() : null,
			// The Connect popup's outcome, once, for the admin who clicked it.
			'notice' => $this->notice($userId),
			'store' => $storeId !== null ? $this->store($storeId) : null,
			'stores' => array_map(fn (array $store) => $store + [
				'connected' => $connected && $this->connection->integrationId($store['id']) !== null,
			], $this->stores->all()),
			'settings' => $storeId !== null && $connected ? $this->plugin->settings()->get($storeId) : null,
			'sync' => $ready ? $this->plugin->sync()->summary((int) $storeId) : null,
			'storefront' => $ready ? $this->plugin->storefront()->get((int) $storeId) : null,
			'opencart' => ['version' => $this->version->name(), 'supported' => $this->version->supported()],
			// A newer version of the extension, once a day from the CDN.
			'update' => $this->plugin->updates()->available(),
			// The storefront answers only its maintenance page: no callback, no run URL.
			'maintenance' => $this->stores->maintenance(),
			// The cron that runs the sync, and whether it does.
			'runner' => $connected ? [
				'cron' => $this->runner->cronLine(),
				'url' => $this->runner->url(),
				'last_run' => $this->runner->lastRun() ?: null,
				'waiting_since' => $this->runner->waitingSince() ?: null,
				// Jobs due for a quarter of an hour: no cron runs them.
				'late' => $this->runner->waitingSince() > 0 && $this->runner->waitingSince() < time() - 900,
				'failed' => $storeId !== null ? $this->jobs->failed($storeId) : 0,
			] : null,
		];
	}

	/**
	 * The project's engines, for the setup.
	 *
	 * @return list<array{id: string, title: string, status: string}>
	 */
	public function engines(int $storeId): array {
		$project = $this->project($storeId);
		$engines = $this->api->request('GET', '/api/engines', null, $project ? ['project_id' => $project] : []);

		return array_map(static fn (array $engine) => [
			'id' => (string) $engine['id'],
			'title' => (string) $engine['title'],
			'status' => (string) $engine['status'],
		], array_values(array_filter($engines['data'] ?? [], 'is_array')));
	}

	/**
	 * Saves the store's setup and sends it to Bitlane, which sets the store up
	 * in the background: engine, indexes, keys and widget. Its sync starts
	 * once Bitlane says it's ready (status()).
	 *
	 * @param array<string, mixed> $input
	 *
	 * @return array<string, mixed> the status
	 */
	public function setup(int $storeId, int $userId, array $input): array {
		$integrationId = $this->connection->integrationId($storeId);
		if (!$integrationId) {
			throw new BitlaneError('not_connected', $this->translator->t('Connect the store to Bitlane first.'), 409);
		}
		$site = $this->plugin->site();

		$kinds = array_column($site->sources($storeId), 'kind', 'id');
		$sources = array_values(array_intersect($this->strings($input['sources'] ?? []), array_keys($kinds)));
		if (!$sources) {
			throw new BitlaneError('no_sources', $this->translator->t('Choose at least one kind of content to search.'), 400);
		}
		$outside = array_values(array_intersect($this->strings($input['outside_widget'] ?? []), $sources));
		$offered = array_column($site->facets($sources), 'sources', 'attribute');
		$facets = array_values(array_intersect($this->strings($input['facets'] ?? []), array_keys($offered)));
		$sorting = $this->sorting($sources, $kinds, is_array($input['sorting'] ?? null) ? $input['sorting'] : []);
		$engineId = is_string($input['engine_id'] ?? null) && $input['engine_id'] !== '' ? $input['engine_id'] : null;
		$selector = trim(strip_tags((string) ($input['search_selector'] ?? '')));
		$color = preg_match('/^#(?:[0-9a-f]{3}){1,2}$/i', (string) ($input['brand_color'] ?? '')) ? (string) $input['brand_color'] : '';
		$logo = filter_var((string) ($input['logo_url'] ?? ''), FILTER_VALIDATE_URL) && preg_match('#^https?://#', (string) $input['logo_url']) ? (string) $input['logo_url'] : '';
		$documents = in_array($input['product_documents'] ?? null, Settings::PRODUCT_DOCUMENTS, true) ? (string) $input['product_documents'] : Settings::PER_VARIATION;

		$languages = $this->stores->languages();
		$currency = $this->stores->currency($storeId);
		$labels = [];
		foreach ($languages as $language) {
			foreach ($site->facets($sources, $language['id']) as $facet) {
				$labels[$facet['attribute']][$language['code']] = $facet['label'];
			}
		}

		$setup = [
			// Each with its own indexes, all in the store's currency, shown as
			// the store shows prices in that language.
			'languages' => array_map(fn (array $language) => array_filter([
				'code' => $language['code'],
				'currency' => $currency,
				'format' => $site->currencyFormat($storeId, $language['opencart']),
			]), $languages),
			// Each source has its own index; the widget searches those not
			// kept for the store's own use.
			'sources' => array_map(static fn (string $id) => [
				'id' => $id,
				'kind' => $kinds[$id],
				'widget' => !in_array($id, $outside, true),
				'sorting' => $sorting[$id],
			], $sources),
			// In the order chosen (the widget shows them so), each a filter
			// of its sources' indexes only.
			'facets' => array_map(static fn (string $attribute) => [
				'attribute' => $attribute,
				'labels' => $labels[$attribute] ?? new \stdClass(),
				'sources' => $offered[$attribute],
			], $facets),
		];
		if ($selector !== '') {
			$setup['search_selector'] = $selector;
		}
		if ($engineId) {
			$setup['engine_id'] = $engineId;
		}
		// Bitlane uses them for a widget without colours or a logo only.
		$brand = array_filter(['primary' => $color, 'logo_url' => $logo]);
		if ($brand) {
			$setup['brand'] = $brand;
		}

		$this->api->request('PUT', '/api/integrations/' . rawurlencode($integrationId) . '/setup', $setup);
		$this->plugin->settings()->save($storeId, [
			'engine_id' => $engineId,
			'sources' => $sources,
			'outside_widget' => $outside,
			'facets' => $facets,
			'sorting' => $sorting,
			'search_selector' => $selector,
			'brand_color' => $color,
			'logo_url' => $logo,
			'product_documents' => $documents,
			'languages' => array_column($languages, 'code'),
		]);

		return $this->status($storeId, $userId);
	}

	/**
	 * Syncs a source again, or every chosen one.
	 *
	 * @return array<string, mixed> the status
	 */
	public function resync(int $storeId, int $userId, ?string $source): array {
		$this->plugin->sync()->resync($storeId, $source);

		return $this->status($storeId, $userId);
	}

	/**
	 * The storefront's choices: the widget on the pages, and which order
	 * statuses are a purchase and a refund.
	 *
	 * @param array<string, mixed> $input
	 *
	 * @return array<string, mixed> the status
	 */
	public function storefront(int $storeId, int $userId, array $input): array {
		$ids = static fn (mixed $list): array => is_array($list) ? array_values(array_map('intval', array_filter($list, 'is_numeric'))) : [];
		$this->plugin->storefront()->configure($storeId, !empty($input['enabled']), $ids($input['paid_statuses'] ?? []), $ids($input['refund_statuses'] ?? []));

		return $this->status($storeId, $userId);
	}

	/**
	 * Runs waiting jobs for a few seconds: the screen does it while it's open,
	 * so a store without a cron still syncs.
	 *
	 * @return array<string, mixed> the status
	 */
	public function run(int $storeId, int $userId): array {
		$this->runner->run(8);

		return $this->status($storeId, $userId);
	}

	/**
	 * The store's jobs for the Jobs tab: waiting, running and failed (a week).
	 *
	 * @return array{jobs: list<array<string, mixed>>, waiting: int, failed: int}
	 */
	public function jobs(int $storeId): array {
		return ['jobs' => $this->jobs->list($storeId), 'waiting' => $this->jobs->waiting($storeId), 'failed' => $this->jobs->failed($storeId)];
	}

	/**
	 * Runs a failed job again.
	 *
	 * @return array{jobs: list<array<string, mixed>>, waiting: int, failed: int}
	 */
	public function jobAgain(int $storeId, int $id): array {
		$this->jobs->again($storeId, $id);

		return $this->jobs($storeId);
	}

	/**
	 * Deletes a job that isn't running.
	 *
	 * @return array{jobs: list<array<string, mixed>>, waiting: int, failed: int}
	 */
	public function jobDelete(int $storeId, int $id): array {
		$this->jobs->delete($storeId, $id);

		return $this->jobs($storeId);
	}

	/**
	 * The consent page's URL, to start a connection from this store.
	 */
	public function authorize(int $storeId, int $userId): string {
		return $this->oauth->authorizeUrl($userId, $storeId);
	}

	/**
	 * The user's projects, for a store without a site in Bitlane yet (a grant
	 * on one project lists that one only).
	 *
	 * @return list<array{id: string, title: string}>
	 */
	public function projects(): array {
		$projects = $this->api->request('GET', '/api/projects');

		return array_map(static fn (array $project) => [
			'id' => (string) $project['id'],
			'title' => (string) ($project['title'] ?? $project['name'] ?? $project['id']),
		], array_values(array_filter($projects['data'] ?? [], 'is_array')));
	}

	/**
	 * Connects the store in Bitlane (the grant's site for its address,
	 * created or found), in the grant's project or the given one.
	 */
	public function connectStore(int $storeId, ?string $projectId = null): void {
		$body = ['platform' => 'opencart', 'site_url' => $this->stores->url($storeId), 'site_name' => $this->stores->name($storeId)];
		if ($projectId) {
			$body['project_id'] = $projectId;
		}
		$integration = $this->api->request('POST', '/api/integrations', $body);
		$this->connection->setIntegrationId($storeId, (string) $integration['id']);
	}

	/**
	 * Disconnects the store: Bitlane deletes its keys; the engine, indexes and
	 * documents stay. The store forgets everything about Bitlane, its jobs
	 * too. The grant ends with the install's last connected store.
	 */
	public function disconnect(int $storeId): void {
		$integrationId = $this->connection->integrationId($storeId);
		if ($integrationId) {
			try {
				$this->api->request('DELETE', '/api/integrations/' . rawurlencode($integrationId));
			} catch (BitlaneError) {
				// Gone already, or Bitlane can't be reached: the store forgets it anyway.
			}
		}
		$this->state->forgetStore($storeId);
		$this->jobs->cancel($storeId);
		$others = array_filter($this->stores->all(), fn (array $store) => $this->connection->integrationId($store['id']) !== null);
		if (!$others) {
			$this->oauth->revoke();
		}
	}

	/**
	 * Keeps the popup's outcome for the admin who clicked Connect.
	 *
	 * @param array{type: string, message: string} $notice
	 */
	public function setNotice(int $userId, array $notice): void {
		$this->state->setJson('notice:' . $userId, $notice);
	}

	/**
	 * @return array<string, mixed>|null
	 */
	private function notice(int $userId): ?array {
		$notice = $this->state->json('notice:' . $userId);
		if ($notice) {
			$this->state->set('notice:' . $userId, null);
		}

		return $notice ?: null;
	}

	/**
	 * @return array<string, mixed>
	 */
	private function store(int $storeId): array {
		if (!$this->stores->exists($storeId)) {
			throw new BitlaneError('no_store', $this->translator->t('This store doesn’t exist.'), 404);
		}

		$site = $this->plugin->site();
		$sources = $site->sources($storeId);

		return [
			'id' => $storeId,
			'name' => $this->stores->name($storeId),
			'url' => $this->stores->url($storeId),
			'languages' => array_map(static fn (array $l) => ['code' => $l['code'], 'name' => $l['name']], $this->stores->languages()),
			'currency' => $this->stores->currency($storeId),
			'sources' => $sources,
			'facets' => $site->facets(array_column($sources, 'id')),
			'sorting' => $site->sorting(),
			'logo_url' => $site->logoUrl($storeId),
			'order_statuses' => $this->stores->orderStatuses($this->translator->code()),
		];
	}

	/**
	 * The project of the store's site, else the grant's.
	 */
	private function project(int $storeId): ?string {
		$integrationId = $this->connection->integrationId($storeId);
		if ($integrationId) {
			$integration = $this->api->request('GET', '/api/integrations/' . rawurlencode($integrationId));
			if (!empty($integration['project_id'])) {
				return (string) $integration['project_id'];
			}
		}
		$project = $this->connection->get()['project_id'] ?? null;

		return is_string($project) ? $project : null;
	}

	/**
	 * Each source's sort choices: those asked for that its kind has, in their
	 * order, else its kind's defaults.
	 *
	 * @param list<string> $sources
	 * @param array<string, string> $kinds source => its kind
	 * @param array<mixed> $chosen source => the choices asked for
	 *
	 * @return array<string, list<string>>
	 */
	private function sorting(array $sources, array $kinds, array $chosen): array {
		$offered = $this->plugin->site()->sorting();
		$sorting = [];
		foreach ($sources as $source) {
			$kind = $offered[$kinds[$source]];
			$sorting[$source] = isset($chosen[$source]) && is_array($chosen[$source])
				? array_values(array_intersect($this->strings($chosen[$source]), array_column($kind['choices'], 'id')))
				: $kind['defaults'];
		}

		return $sorting;
	}

	/**
	 * @return list<string>
	 */
	private function strings(mixed $list): array {
		return is_array($list) ? array_values(array_filter($list, 'is_string')) : [];
	}
}
