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
 * OpenCart's database connection (the registry's `db`, the same class in 4.0
 * and 4.1), with the table prefix and the few shapes our code reads.
 */
final class Database {
	/** @var array<string, bool> */
	private array $tables = [];

	public function __construct(private \Opencart\System\Library\DB $db, private string $prefix) {
	}

	/**
	 * A table's name with the install's prefix, quoted.
	 */
	public function table(string $name): string {
		return '`' . $this->prefix . $name . '`';
	}

	/**
	 * Whether the install has the table (OpenCart's schema differs between
	 * versions, even patch releases), asked once per request.
	 */
	public function exists(string $name): bool {
		return $this->tables[$name] ??= $this->value("SHOW TABLES LIKE '" . $this->db->escape(addcslashes($this->prefix . $name, '%_')) . "'") !== null;
	}

	public function escape(string $value): string {
		return $this->db->escape($value);
	}

	/**
	 * A quoted, escaped string.
	 */
	public function quote(string $value): string {
		return "'" . $this->db->escape($value) . "'";
	}

	public function execute(string $sql): void {
		$this->db->query($sql);
	}

	/**
	 * @return list<array<string, mixed>>
	 */
	public function rows(string $sql): array {
		$result = $this->db->query($sql);

		return is_object($result) && isset($result->rows) && is_array($result->rows) ? array_values($result->rows) : [];
	}

	/**
	 * The first row, or null.
	 *
	 * @return array<string, mixed>|null
	 */
	public function row(string $sql): ?array {
		return $this->rows($sql)[0] ?? null;
	}

	/**
	 * The first column of the first row, or null.
	 */
	public function value(string $sql): mixed {
		$row = $this->row($sql);

		return $row === null ? null : reset($row);
	}

	public function affected(): int {
		return $this->db->countAffected();
	}
}
