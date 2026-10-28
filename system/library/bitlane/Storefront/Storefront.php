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

namespace Bitlane\OpenCart\Storefront;

use Bitlane\OpenCart\Connection\BitlaneApi;
use Bitlane\OpenCart\Connection\ConnectionStore;
use Bitlane\OpenCart\Queue\Jobs;
use Bitlane\OpenCart\Site\Stores;
use Bitlane\OpenCart\Support\BitlaneError;
use Bitlane\OpenCart\Support\State;

/**
 * The search widget on a store's pages (docs/opencart-plan.md OC15, OC16):
 * the snippet an event on catalog/view/common/header/after puts before
 * </head> (window.BitlaneWidgetConfig, the queue for Bitlane.track() calls
 * made before the loader has run, and the loader as a module script).
 * Pages never call Bitlane: the store's active widget and its loader are
 * kept in bitlane_state, from the integration's details each time the
 * Bitlane screen loads and every hour (job storefront).
 *
 * Kept with it: the merchant's switch, and which order statuses count as a
 * purchase and as a refund (ShopEvents, OC17).
 *
 * With a cookie policy (config_cookie_id), tracking starts off until the
 * visitor accepts it (OpenCart's policy cookie, read per request: OpenCart
 * has no page cache); everywhere the widget also follows Google Consent
 * Mode, and other consent extensions call Bitlane.consent().
 */
final class Storefront {
	public const JOB = 'storefront';
	private const NAME = 'storefront';

	/** OpenCart's own statuses for money given back: Canceled, Refunded, Reversed, Chargeback, Voided. */
	private const REFUND_STATUSES = [7, 11, 12, 13, 16];

	public function __construct(
		private State $state,
		private Stores $stores,
		private BitlaneApi $api,
		private ConnectionStore $connection,
		private Jobs $jobs,
	) {
	}

	/**
	 * What's kept for the store, the defaults for what isn't set.
	 *
	 * @return array{enabled: bool, widget_id: string, widget_title: string, loader_url: string, paid_statuses: list<int>, refund_statuses: list<int>}
	 */
	public function get(int $storeId): array {
		$stored = $this->state->json(self::NAME, $storeId);
		$refunds = isset($stored['refund_statuses']) ? $this->ids($stored['refund_statuses']) : $this->defaultRefunds();

		return [
			'enabled' => (bool) ($stored['enabled'] ?? true),
			'widget_id' => (string) ($stored['widget_id'] ?? ''),
			'widget_title' => (string) ($stored['widget_title'] ?? ''),
			'loader_url' => (string) ($stored['loader_url'] ?? ''),
			'paid_statuses' => isset($stored['paid_statuses']) ? $this->ids($stored['paid_statuses']) : $this->defaultPaid($storeId, $refunds),
			'refund_statuses' => $refunds,
		];
	}

	/**
	 * The merchant's choices: the widget on the pages, and the statuses.
	 *
	 * @param list<int> $paid
	 * @param list<int> $refunds
	 */
	public function configure(int $storeId, bool $enabled, array $paid, array $refunds): void {
		$known = array_column($this->stores->orderStatuses(), 'id');
		$this->save($storeId, [
			'enabled' => $enabled,
			'paid_statuses' => array_values(array_intersect($this->ids($paid), $known)),
			// A status can't be both.
			'refund_statuses' => array_values(array_diff(array_intersect($this->ids($refunds), $known), $paid)),
		]);
	}

	/**
	 * Keeps the store's first active widget from its integration's details,
	 * with the loader from its install snippet, and its hourly refresh.
	 *
	 * @param array<mixed> $integration GET /api/integrations/:id
	 */
	public function remember(int $storeId, array $integration): void {
		$widget = ['widget_id' => '', 'widget_title' => '', 'loader_url' => ''];
		foreach ((array) ($integration['widgets'] ?? []) as $candidate) {
			if (is_array($candidate) && ($candidate['status'] ?? '') === 'active' && preg_match('/<script[^>]+src="(https:\/\/[^"]+)"/', (string) ($candidate['install_snippet'] ?? ''), $match)) {
				$widget = ['widget_id' => (string) ($candidate['id'] ?? ''), 'widget_title' => (string) ($candidate['title'] ?? ''), 'loader_url' => $match[1]];
				break;
			}
		}
		$this->save($storeId, $widget);
		$this->jobs->push($storeId, self::JOB, [], 3600, self::JOB);
	}

	/**
	 * The hourly refresh: the widget may have been switched off or on in
	 * Bitlane. Bitlane being unreachable keeps what's known (the job retries).
	 */
	public function refresh(int $storeId): void {
		$id = $this->connection->integrationId($storeId);
		if (!$id || !$this->connection->connected()) {
			return;
		}
		try {
			$this->remember($storeId, $this->api->request('GET', '/api/integrations/' . rawurlencode($id)));
		} catch (BitlaneError $error) {
			if ($error->status !== 404) {
				throw $error;
			}
			// The store's site is gone from Bitlane: no widget, and no refresh.
			$this->save($storeId, ['widget_id' => '', 'widget_title' => '', 'loader_url' => '']);
		}
	}

	/**
	 * The snippet for a page of the store: empty when switched off or
	 * without an active widget.
	 *
	 * @param bool $waitForConsent the store has a cookie policy the visitor hasn't accepted
	 */
	public function snippet(int $storeId, bool $waitForConsent): string {
		$stored = $this->get($storeId);
		if (!$stored['enabled'] || $stored['widget_id'] === '' || $stored['loader_url'] === '') {
			return '';
		}
		$config = ['id' => $stored['widget_id']];
		if ($waitForConsent) {
			$config['tracking'] = false;
		}
		$json = (string) json_encode($config, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);

		return '<script>window.BitlaneWidgetConfig = ' . $json . ';'
			. 'window.Bitlane = window.Bitlane || { q: [], track: function () { this.q.push(arguments) } };</script>' . "\n"
			. '<script type="module" src="' . htmlspecialchars($stored['loader_url'], ENT_QUOTES, 'UTF-8') . '"></script>' . "\n";
	}

	/**
	 * A page's HTML with the snippet before its </head>; unchanged without one.
	 */
	public static function insert(string $html, string $snippet): string {
		$at = stripos($html, '</head>');

		return $snippet === '' || $at === false ? $html : substr($html, 0, $at) . $snippet . substr($html, $at);
	}

	/**
	 * Paid by default: the store's complete and processing statuses, but
	 * not its new orders' (Pending: bank transfers and cash on delivery not
	 * paid yet), nor a refund's (OpenCart counts Reversed as processing).
	 *
	 * @param list<int> $refunds
	 *
	 * @return list<int>
	 */
	private function defaultPaid(int $storeId, array $refunds): array {
		$statuses = [];
		foreach (['config_complete_status', 'config_processing_status'] as $key) {
			$value = json_decode($this->stores->setting($storeId, $key), true);
			$statuses = array_merge($statuses, $this->ids(is_array($value) ? $value : []));
		}
		$excluded = array_merge($refunds, [(int) $this->stores->setting($storeId, 'config_order_status_id')]);

		return array_values(array_unique(array_diff($statuses, $excluded)));
	}

	/**
	 * @return list<int>
	 */
	private function defaultRefunds(): array {
		return array_values(array_intersect(self::REFUND_STATUSES, array_column($this->stores->orderStatuses(), 'id')));
	}

	/**
	 * @return list<int>
	 */
	private function ids(mixed $list): array {
		return is_array($list) ? array_values(array_unique(array_filter(array_map('intval', $list)))) : [];
	}

	/**
	 * @param array<string, mixed> $changes
	 */
	private function save(int $storeId, array $changes): void {
		$this->state->setJson(self::NAME, array_merge($this->state->json(self::NAME, $storeId), $changes), $storeId);
	}
}
