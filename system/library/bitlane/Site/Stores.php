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

namespace Bitlane\OpenCart\Site;

use Bitlane\OpenCart\Support\Database;

/**
 * The install's stores, each a site of its own in Bitlane under the install's
 * one connection (docs/opencart-plan.md OC4): store 0, the default, at the
 * install's address, and the rows of OpenCart's `store` table. Every store
 * has every enabled language of the install (OC12).
 */
final class Stores {
	/**
	 * @param string $defaultUrl store 0's address (HTTP_CATALOG in the admin, HTTP_SERVER on the storefront)
	 */
	public function __construct(private Database $db, private string $defaultUrl) {
	}

	/**
	 * @return list<array{id: int, name: string, url: string}>
	 */
	public function all(): array {
		$stores = [['id' => 0, 'name' => $this->name(0), 'url' => $this->url(0)]];
		foreach ($this->db->rows('SELECT `store_id`, `name`, `url` FROM ' . $this->db->table('store') . ' ORDER BY `store_id`') as $row) {
			$stores[] = ['id' => (int) $row['store_id'], 'name' => (string) $row['name'], 'url' => $this->clean((string) $row['url'])];
		}

		return $stores;
	}

	public function exists(int $storeId): bool {
		return $storeId === 0 || $this->db->value('SELECT 1 FROM ' . $this->db->table('store') . ' WHERE `store_id` = ' . $storeId) !== null;
	}

	/**
	 * The store's address, without a trailing slash.
	 */
	public function url(int $storeId): string {
		if ($storeId === 0) {
			return $this->clean($this->defaultUrl);
		}

		return $this->clean((string) $this->db->value('SELECT `url` FROM ' . $this->db->table('store') . ' WHERE `store_id` = ' . $storeId));
	}

	public function name(int $storeId): string {
		$name = $this->setting($storeId, 'config_name');
		if ($name === '' && $storeId > 0) {
			$name = (string) $this->db->value('SELECT `name` FROM ' . $this->db->table('store') . ' WHERE `store_id` = ' . $storeId);
		}

		return html_entity_decode($name, ENT_QUOTES, 'UTF-8');
	}

	/**
	 * The install's enabled languages, in OpenCart's order, with their codes
	 * as Bitlane has them (en-gb → en-GB) and as OpenCart does.
	 *
	 * @return list<array{id: int, code: string, opencart: string, name: string}>
	 */
	public function languages(): array {
		return array_map(static fn (array $row) => [
			'id' => (int) $row['language_id'],
			'code' => self::languageCode((string) $row['code']),
			'opencart' => (string) $row['code'],
			'name' => html_entity_decode((string) $row['name'], ENT_QUOTES, 'UTF-8'),
		], $this->db->rows('SELECT `language_id`, `code`, `name` FROM ' . $this->db->table('language') . " WHERE `status` = '1' ORDER BY `sort_order`, `name`"));
	}

	/**
	 * OpenCart's order statuses (Localisation › Order Statuses), named in a
	 * language (OpenCart's code), else in the first enabled one.
	 *
	 * @return list<array{id: int, name: string}>
	 */
	public function orderStatuses(string $language = ''): array {
		$languages = $this->languages();
		$languageId = $languages[0]['id'] ?? 1;
		foreach ($languages as $candidate) {
			if ($candidate['opencart'] === $language) {
				$languageId = $candidate['id'];
			}
		}

		return array_map(static fn (array $row) => [
			'id' => (int) $row['order_status_id'],
			'name' => html_entity_decode((string) $row['name'], ENT_QUOTES, 'UTF-8'),
		], $this->db->rows('SELECT `order_status_id`, `name` FROM ' . $this->db->table('order_status') . ' WHERE `language_id` = ' . $languageId . ' ORDER BY `name`'));
	}

	/**
	 * Whether the storefront shows only the maintenance page (System ›
	 * Settings › Server): it stops the OAuth callback and the run URL too.
	 */
	public function maintenance(): bool {
		return $this->setting(0, 'config_maintenance') === '1';
	}

	/**
	 * The store's default currency (ISO 4217).
	 */
	public function currency(int $storeId): string {
		return strtoupper($this->setting($storeId, 'config_currency'));
	}

	/**
	 * en-gb → en-GB, it → it.
	 */
	public static function languageCode(string $code): string {
		$parts = explode('-', strtolower($code), 2);

		return isset($parts[1]) ? $parts[0] . '-' . strtoupper($parts[1]) : $parts[0];
	}

	/**
	 * A store's setting, store 0's when the store has none.
	 */
	public function setting(int $storeId, string $key): string {
		$value = $this->db->value('SELECT `value` FROM ' . $this->db->table('setting')
			. ' WHERE `store_id` IN (0, ' . $storeId . ") AND `code` = 'config' AND `key` = " . $this->db->quote($key)
			. ' ORDER BY `store_id` DESC LIMIT 1');

		return is_string($value) ? $value : '';
	}

	private function clean(string $url): string {
		return rtrim($url, '/');
	}
}
