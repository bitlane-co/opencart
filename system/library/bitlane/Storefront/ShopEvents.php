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
use Bitlane\OpenCart\Site\Settings;
use Bitlane\OpenCart\Site\Stores;
use Bitlane\OpenCart\Support\BitlaneError;
use Bitlane\OpenCart\Support\Database;

/**
 * The store's events, sent to Bitlane from the server (docs/opencart-plan.md
 * OC17, docs/conversions-plan.md C6, C11): POST /api/integrations/:id/events
 * in the language whose products index holds their documents. Ad blockers
 * can't drop them and shoppers can't fake them, unlike Bitlane.track().
 *
 * The widget's session (its bitlane_session cookie, written only with
 * tracking on) is kept for OpenCart's session on every addition to the cart
 * (bitlane_cart), then for the order when checkout saves it
 * (bitlane_order), since payment callbacks change its status without the
 * shopper's cookies. Each event is a job whose arguments carry its id, so a
 * retry sends the same event:
 * - an addition to the cart (checkout/cart.add answering success);
 * - a purchase, the first time an order enters a paid status;
 * - a refund of the whole order (OpenCart has no partial refunds), the
 *   first time a reported order enters a refund status.
 */
final class ShopEvents {
	public const ADDITION = 'event_cart';
	public const PURCHASE = 'event_purchase';
	public const REFUND = 'event_refund';

	public const CART_TABLE = 'bitlane_cart';
	public const ORDER_TABLE = 'bitlane_order';

	public function __construct(
		private Database $db,
		private BitlaneApi $api,
		private ConnectionStore $connection,
		private Stores $stores,
		private Settings $settings,
		private Storefront $storefront,
		private Jobs $jobs,
	) {
	}

	/**
	 * A product added to the cart: the widget's session kept for OpenCart's,
	 * the addition queued.
	 *
	 * @param string $openCartSession OpenCart's session id
	 * @param string $session the widget's, from its cookie (empty without one)
	 */
	public function added(int $storeId, int $languageId, string $openCartSession, string $session, int $productId, int $quantity, string $userAgent): void {
		$language = $this->language($storeId, $languageId);
		if ($language === '' || $quantity <= 0 || !$this->connection->integrationId($storeId)) {
			return;
		}
		$session = self::session($session);
		if ($session !== '' && $openCartSession !== '') {
			$this->db->execute('INSERT INTO ' . $this->db->table(self::CART_TABLE) . ' (`opencart_session`, `session_id`, `updated_at`) VALUES ('
				. $this->db->quote(substr($openCartSession, 0, 64)) . ', ' . $this->db->quote($session) . ", '" . date('Y-m-d H:i:s') . "')"
				. ' ON DUPLICATE KEY UPDATE `session_id` = VALUES(`session_id`), `updated_at` = VALUES(`updated_at`)');
		}
		$master = (int) $this->db->value('SELECT `master_id` FROM ' . $this->db->table('product') . ' WHERE `product_id` = ' . $productId);
		$this->jobs->push($storeId, self::ADDITION, [$language, [[
			'id' => self::uuid(),
			'type' => 'add_to_cart',
			'session_id' => $session,
			'at' => time(),
			'document_id' => $this->documentId($storeId, $productId, $master),
			'product_id' => (string) ($master ?: $productId),
			'quantity' => min($quantity, 100000),
			'device' => self::device($userAgent),
		]]]);
	}

	/**
	 * Checkout saved an order (added, or changed before it's placed): the
	 * session of the cart's last addition, else the widget's now, is kept
	 * for it.
	 */
	public function orderSaved(int $orderId, string $openCartSession, string $session): void {
		$kept = $openCartSession === '' ? null : $this->db->value('SELECT `session_id` FROM ' . $this->db->table(self::CART_TABLE)
			. ' WHERE `opencart_session` = ' . $this->db->quote(substr($openCartSession, 0, 64)));
		$session = is_string($kept) && $kept !== '' ? $kept : self::session($session);
		if ($orderId <= 0 || $session === '') {
			return;
		}
		$this->db->execute('INSERT INTO ' . $this->db->table(self::ORDER_TABLE) . ' (`order_id`, `session_id`) VALUES (' . $orderId . ', ' . $this->db->quote($session) . ')'
			. ' ON DUPLICATE KEY UPDATE `session_id` = VALUES(`session_id`)');
	}

	/**
	 * An order's new status (addHistory): paid for the first time queues its
	 * purchase; a refund status after it was reported queues its refund.
	 */
	public function statusChanged(int $orderId, int $statusId): void {
		$storeId = $this->db->value('SELECT `store_id` FROM ' . $this->db->table('order') . ' WHERE `order_id` = ' . $orderId);
		if ($storeId === null || !$this->connection->integrationId((int) $storeId)) {
			return;
		}
		$storeId = (int) $storeId;
		$statuses = $this->storefront->get($storeId);
		$reported = $this->reported($orderId);
		if (in_array($statusId, $statuses['paid_statuses'], true) && empty($reported['purchase_at'])) {
			$this->jobs->push($storeId, self::PURCHASE, [$orderId, self::uuid()], 0, 'purchase:' . $orderId);
		} elseif (in_array($statusId, $statuses['refund_statuses'], true) && !empty($reported['purchase_at']) && empty($reported['refund_at'])) {
			$this->jobs->push($storeId, self::REFUND, [$orderId, self::uuid()], 0, 'refund:' . $orderId);
		}
	}

	/**
	 * The additions job: sends them as they were.
	 *
	 * @param array<mixed> $args the language, the events
	 */
	public function sendAdditions(int $storeId, array $args): void {
		$this->send($storeId, (string) ($args[0] ?? ''), array_values((array) ($args[1] ?? [])));
	}

	/**
	 * The purchase job: the order as it is now, unless it was refunded
	 * meanwhile.
	 *
	 * @param array<mixed> $args the order, the event's id
	 */
	public function sendPurchase(int $storeId, array $args): void {
		$order = $this->order((int) ($args[0] ?? 0));
		if ($order === null || !empty($this->reported((int) $order['order_id'])['purchase_at'])
			|| in_array((int) $order['order_status_id'], $this->storefront->get($storeId)['refund_statuses'], true)) {
			return;
		}
		$this->send($storeId, $this->language($storeId, (int) $order['language_id']), [[
			'id' => (string) ($args[1] ?? self::uuid()),
			'type' => 'purchase',
			'session_id' => (string) ($this->reported((int) $order['order_id'])['session_id'] ?? ''),
			'at' => (int) strtotime((string) $order['date_added']),
			'purchase' => [
				'order_id' => (string) $order['order_id'],
				'currency' => strtoupper((string) $order['currency_code']),
				'value' => round((float) $order['total'] * (float) $order['currency_value'], 6),
				// Bitlane takes 200.
				'items' => array_slice($this->items($storeId, $order), 0, 200),
			],
		]]);
		$this->note((int) $order['order_id'], 'purchase_at');
	}

	/**
	 * The refund job: the whole order, once.
	 *
	 * @param array<mixed> $args the order, the event's id
	 */
	public function sendRefund(int $storeId, array $args): void {
		$order = $this->order((int) ($args[0] ?? 0));
		if ($order === null || !empty($this->reported((int) $order['order_id'])['refund_at'])) {
			return;
		}
		$this->send($storeId, $this->language($storeId, (int) $order['language_id']), [[
			'id' => (string) ($args[1] ?? self::uuid()),
			'type' => 'refund',
			'session_id' => (string) ($this->reported((int) $order['order_id'])['session_id'] ?? ''),
			'at' => time(),
			'refund' => [
				'order_id' => (string) $order['order_id'],
				'refund_id' => 'order',
				'currency' => strtoupper((string) $order['currency_code']),
				'value' => round((float) $order['total'] * (float) $order['currency_value'], 6),
				'items' => array_slice($this->items($storeId, $order), 0, 200),
			],
		]]);
		$this->note((int) $order['order_id'], 'refund_at');
	}

	/**
	 * Sessions of carts not touched for 90 days go: their orders are long
	 * placed, or never will be.
	 */
	public function prune(): void {
		$this->db->execute('DELETE FROM ' . $this->db->table(self::CART_TABLE) . " WHERE `updated_at` < '" . date('Y-m-d H:i:s', time() - 90 * 86400) . "'");
	}

	/**
	 * A product's document id, as the setup makes them: the variant's own,
	 * or its product's when products are one document each.
	 */
	public function documentId(int $storeId, int $productId, int $masterId): string {
		$perProduct = $this->settings->get($storeId)['product_documents'] === Settings::PER_PRODUCT;

		return (string) ($perProduct && $masterId > 0 ? $masterId : $productId);
	}

	/**
	 * The widget's session id, when it is one.
	 */
	public static function session(string $cookie): string {
		return preg_match('/^[0-9a-f-]{36}$/', $cookie) ? $cookie : '';
	}

	public static function device(string $userAgent): string {
		if (preg_match('/iPad|Tablet|Android(?!.*Mobile)/i', $userAgent)) {
			return 'tablet';
		}

		return preg_match('/Mobile|iPhone|iPod|Android/i', $userAgent) ? 'mobile' : 'desktop';
	}

	/**
	 * The order's lines as the shopper paid them: tax included, in the
	 * order's currency.
	 *
	 * @param array<string, mixed> $order
	 *
	 * @return list<array{document_id: string, product_id: string, quantity: int, price: float}>
	 */
	private function items(int $storeId, array $order): array {
		$items = [];
		foreach ($this->db->rows('SELECT `product_id`, `master_id`, `quantity`, `price`, `tax` FROM ' . $this->db->table('order_product')
			. ' WHERE `order_id` = ' . (int) $order['order_id'] . ' ORDER BY `order_product_id`') as $line) {
			if ((int) $line['quantity'] <= 0) {
				continue;
			}
			$master = (int) $line['master_id'];
			$items[] = [
				'document_id' => $this->documentId($storeId, (int) $line['product_id'], $master),
				// A variant's product: it matches a click on another variant.
				'product_id' => (string) ($master ?: (int) $line['product_id']),
				'quantity' => min((int) $line['quantity'], 100000),
				'price' => round(((float) $line['price'] + (float) $line['tax']) * (float) $order['currency_value'], 6),
			];
		}

		return $items;
	}

	/**
	 * @param list<array<string, mixed>> $events
	 */
	private function send(int $storeId, string $language, array $events): void {
		$id = $this->connection->integrationId($storeId);
		if (!$id) {
			throw new BitlaneError('not_connected', 'This store isn’t connected to Bitlane.', 409);
		}
		if ($language === '' || !$events) {
			return;
		}
		$this->api->request('POST', '/api/integrations/' . rawurlencode($id) . '/events', ['language' => $language, 'events' => $events]);
	}

	/**
	 * A language of the install as the setup sent it ("it-IT"); empty when
	 * the setup doesn't have it (Bitlane would refuse its events).
	 */
	private function language(int $storeId, int $languageId): string {
		$codes = array_column($this->stores->languages(), 'code', 'id');
		$code = (string) ($codes[$languageId] ?? '');

		return in_array($code, $this->settings->get($storeId)['languages'], true) ? $code : '';
	}

	/**
	 * @return array<string, mixed>|null
	 */
	private function order(int $orderId): ?array {
		return $this->db->row('SELECT `order_id`, `language_id`, `currency_code`, `currency_value`, `total`, `order_status_id`, `date_added` FROM '
			. $this->db->table('order') . ' WHERE `order_id` = ' . $orderId);
	}

	/**
	 * What was reported of an order, and its session.
	 *
	 * @return array<string, mixed>
	 */
	private function reported(int $orderId): array {
		return $this->db->row('SELECT * FROM ' . $this->db->table(self::ORDER_TABLE) . ' WHERE `order_id` = ' . $orderId) ?? [];
	}

	private function note(int $orderId, string $column): void {
		$this->db->execute('INSERT INTO ' . $this->db->table(self::ORDER_TABLE) . ' (`order_id`, `session_id`, `' . $column . "`) VALUES ($orderId, '', '" . date('Y-m-d H:i:s') . "')"
			. ' ON DUPLICATE KEY UPDATE `' . $column . '` = VALUES(`' . $column . '`)');
	}

	private static function uuid(): string {
		$bytes = random_bytes(16);
		$bytes[6] = chr(ord($bytes[6]) & 0x0F | 0x40);
		$bytes[8] = chr(ord($bytes[8]) & 0x3F | 0x80);

		return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
	}
}
