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
 * One request at a time through a piece of work, with MySQL's named locks
 * (GET_LOCK): a name held by one connection, not a table or row lock. The
 * lock goes away with the connection if PHP dies holding it.
 */
final class Lock {
	public function __construct(private Database $db) {
	}

	/**
	 * Runs $work holding the lock, waiting up to $wait seconds for it; without
	 * it, runs it anyway (the server's grace window covers a second refresh).
	 *
	 * @template T
	 *
	 * @param callable(): T $work
	 *
	 * @return T
	 */
	public function with(string $name, callable $work, int $wait = 10): mixed {
		$key = $this->key($name);
		$held = (int) $this->db->value('SELECT GET_LOCK(' . $key . ', ' . $wait . ')') === 1;
		try {
			return $work();
		} finally {
			if ($held) {
				$this->db->value('SELECT RELEASE_LOCK(' . $key . ')');
			}
		}
	}

	/**
	 * Runs $work only if the lock is free now; false when another holds it.
	 *
	 * @param callable(): void $work
	 */
	public function attempt(string $name, callable $work): bool {
		$key = $this->key($name);
		if ((int) $this->db->value('SELECT GET_LOCK(' . $key . ', 0)') !== 1) {
			return false;
		}
		try {
			$work();
		} finally {
			$this->db->value('SELECT RELEASE_LOCK(' . $key . ')');
		}

		return true;
	}

	/**
	 * Locks are server-wide: the name carries the install's database.
	 */
	private function key(string $name): string {
		return $this->db->quote(substr('bitlane_' . DB_DATABASE . '_' . $name, 0, 64));
	}
}
