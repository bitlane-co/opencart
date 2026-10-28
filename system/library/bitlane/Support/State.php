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
 * What the extension keeps, in its own table bitlane_state: a value by name,
 * for the whole install (store 0's row with name of the install's) or one
 * store. Never OpenCart's settings, which an update deletes (OpenCart updates
 * an extension by uninstalling it; docs/opencart-plan.md OC18). Every read
 * goes to the database, since another request may have refreshed the tokens
 * meanwhile.
 */
final class State {
	public const TABLE = 'bitlane_state';

	/** The row the install's own values use: stores count from 0. */
	public const INSTALL = -1;

	public function __construct(private Database $db) {
	}

	public function get(string $name, int $storeId = self::INSTALL): ?string {
		$value = $this->db->value('SELECT `value` FROM ' . $this->db->table(self::TABLE)
			. ' WHERE `store_id` = ' . $storeId . ' AND `name` = ' . $this->db->quote($name));

		return is_string($value) ? $value : null;
	}

	/**
	 * Stores a value; null removes it.
	 */
	public function set(string $name, ?string $value, int $storeId = self::INSTALL): void {
		if ($value === null) {
			$this->db->execute('DELETE FROM ' . $this->db->table(self::TABLE)
				. ' WHERE `store_id` = ' . $storeId . ' AND `name` = ' . $this->db->quote($name));

			return;
		}
		$this->db->execute('INSERT INTO ' . $this->db->table(self::TABLE) . ' (`store_id`, `name`, `value`, `updated_at`)'
			// PHP's time, as prune() compares it (MySQL's may be in another zone).
			. ' VALUES (' . $storeId . ', ' . $this->db->quote($name) . ', ' . $this->db->quote($value) . ", '" . date('Y-m-d H:i:s') . "')"
			. ' ON DUPLICATE KEY UPDATE `value` = VALUES(`value`), `updated_at` = VALUES(`updated_at`)');
	}

	/**
	 * A JSON value, an empty array when there's none.
	 *
	 * @return array<string, mixed>
	 */
	public function json(string $name, int $storeId = self::INSTALL): array {
		$data = json_decode((string) $this->get($name, $storeId), true);

		return is_array($data) ? $data : [];
	}

	/**
	 * @param array<string, mixed>|null $value
	 */
	public function setJson(string $name, ?array $value, int $storeId = self::INSTALL): void {
		$this->set($name, $value === null ? null : (string) json_encode($value), $storeId);
	}

	/**
	 * Removes a name from the install and every store.
	 */
	public function forget(string $name): void {
		$this->db->execute('DELETE FROM ' . $this->db->table(self::TABLE) . ' WHERE `name` = ' . $this->db->quote($name));
	}

	/**
	 * Removes everything kept for a store (the install's stays).
	 */
	public function forgetStore(int $storeId): void {
		if ($storeId >= 0) {
			$this->db->execute('DELETE FROM ' . $this->db->table(self::TABLE) . ' WHERE `store_id` = ' . $storeId);
		}
	}

	/**
	 * Removes the names that start with $prefix and were last written before $before.
	 */
	public function prune(string $prefix, int $before): void {
		$this->db->execute('DELETE FROM ' . $this->db->table(self::TABLE)
			. ' WHERE `name` LIKE ' . $this->db->quote(addcslashes($prefix, '%_\\') . '%')
			. " AND `updated_at` < '" . date('Y-m-d H:i:s', $before) . "'");
	}
}
