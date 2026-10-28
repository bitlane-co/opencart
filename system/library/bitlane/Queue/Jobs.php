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

namespace Bitlane\OpenCart\Queue;

use Bitlane\OpenCart\Support\Database;

/**
 * The extension's background work, in its table bitlane_job
 * (docs/opencart-plan.md OC11; OpenCart has no queue): a store, a type (what
 * runs it, Runner), arguments in JSON, when it's due and how many attempts
 * it had. A job is claimed by writing a token and a lock time with one
 * UPDATE (MySQL and MariaDB alike), so two runners never take the same one,
 * and a runner that died lets go when its lock time passes.
 *
 * The table holds work to do, not a history: a job that finishes is deleted
 * at once, one that gives up stays a week with its error (failed_at) for the
 * Jobs tab, then prune() deletes it. Times are PHP's.
 */
final class Jobs {
	public const TABLE = 'bitlane_job';

	/** How long a failed job stays, in days. */
	private const KEEP_FAILED = 7;

	public function __construct(private Database $db) {
	}

	/**
	 * Queues a job, due now or after $delay seconds. With a $key, a job of the
	 * store with that key still waiting is kept instead (not one running: a
	 * recurring job queues its next run while it runs).
	 *
	 * @param array<mixed> $args
	 */
	public function push(int $storeId, string $type, array $args = [], int $delay = 0, ?string $key = null): void {
		if ($key !== null && $this->db->value('SELECT 1 FROM ' . $this->table() . ' WHERE `store_id` = ' . $storeId . ' AND `job_key` = ' . $this->db->quote($key) . ' AND `failed_at` IS NULL AND `lock_token` IS NULL') !== null) {
			return;
		}
		$this->db->execute('INSERT INTO ' . $this->table() . ' SET `store_id` = ' . $storeId
			. ', `type` = ' . $this->db->quote($type)
			. ', `args` = ' . $this->db->quote((string) json_encode($args))
			. ', `job_key` = ' . ($key === null ? 'NULL' : $this->db->quote($key))
			. ", `run_at` = '" . date('Y-m-d H:i:s', time() + $delay) . "'"
			. ", `attempts` = 0, `created_at` = '" . date('Y-m-d H:i:s') . "'");
	}

	/**
	 * Claims the next due job for $lock seconds.
	 *
	 * @return array{id: int, store: int, type: string, args: array<mixed>, attempts: int}|null
	 */
	public function claim(int $lock = 300): ?array {
		$token = bin2hex(random_bytes(16));
		$this->db->execute('UPDATE ' . $this->table() . " SET `lock_token` = '" . $token . "', `locked_until` = '" . date('Y-m-d H:i:s', time() + $lock) . "'"
			. ' WHERE ' . $this->due() . ' ORDER BY `run_at`, `job_id` LIMIT 1');
		$row = $this->db->row('SELECT * FROM ' . $this->table() . " WHERE `lock_token` = '" . $token . "'");
		if ($row === null) {
			return null;
		}
		$args = json_decode((string) $row['args'], true);

		return ['id' => (int) $row['job_id'], 'store' => (int) $row['store_id'], 'type' => (string) $row['type'], 'args' => is_array($args) ? $args : [], 'attempts' => (int) $row['attempts']];
	}

	/**
	 * A job finished: it goes.
	 */
	public function done(int $id): void {
		$this->db->execute('DELETE FROM ' . $this->table() . ' WHERE `job_id` = ' . $id);
	}

	/**
	 * Runs a job again after $delay seconds, one attempt more, noting why.
	 */
	public function retry(int $id, int $delay, string $error): void {
		$this->db->execute('UPDATE ' . $this->table() . " SET `run_at` = '" . date('Y-m-d H:i:s', time() + $delay) . "', `attempts` = `attempts` + 1,"
			. ' `lock_token` = NULL, `locked_until` = NULL, `last_error` = ' . $this->db->quote(mb_substr($error, 0, 1000))
			. ' WHERE `job_id` = ' . $id);
	}

	/**
	 * A job gave up: it stays, with its error, until prune().
	 */
	public function fail(int $id, string $error): void {
		$this->db->execute('UPDATE ' . $this->table() . " SET `failed_at` = '" . date('Y-m-d H:i:s') . "', `attempts` = `attempts` + 1,"
			. ' `lock_token` = NULL, `locked_until` = NULL, `last_error` = ' . $this->db->quote(mb_substr($error, 0, 1000))
			. ' WHERE `job_id` = ' . $id);
	}

	/**
	 * Deletes the jobs that failed more than a week ago.
	 */
	public function prune(): void {
		$this->db->execute('DELETE FROM ' . $this->table() . " WHERE `failed_at` < '" . date('Y-m-d H:i:s', time() - self::KEEP_FAILED * 86400) . "'");
	}

	/**
	 * The store's jobs for the Jobs tab: running first, then due, then later
	 * ones, then failed; at most $limit.
	 *
	 * @return list<array{id: int, type: string, args: array<mixed>, run_at: int, due: bool, attempts: int, running: bool, failed_at: int|null, error: string|null}>
	 */
	public function list(int $storeId, int $limit = 100): array {
		$now = date('Y-m-d H:i:s');
		$rows = $this->db->rows('SELECT * FROM ' . $this->table() . ' WHERE `store_id` = ' . $storeId
			. " ORDER BY `failed_at` IS NOT NULL, (`locked_until` > '" . $now . "') DESC, `run_at`, `job_id` LIMIT " . $limit);

		return array_map(static function (array $row) use ($now): array {
			$args = json_decode((string) $row['args'], true);

			return [
				'id' => (int) $row['job_id'],
				'type' => (string) $row['type'],
				'args' => is_array($args) ? $args : [],
				'run_at' => (int) strtotime((string) $row['run_at']),
				'due' => $row['run_at'] <= $now,
				'attempts' => (int) $row['attempts'],
				'running' => $row['failed_at'] === null && $row['locked_until'] !== null && $row['locked_until'] > $now,
				'failed_at' => $row['failed_at'] !== null ? (int) strtotime((string) $row['failed_at']) : null,
				'error' => $row['last_error'] !== null ? (string) $row['last_error'] : null,
			];
		}, $rows);
	}

	/**
	 * How many of the store's jobs wait to run, of the given types (all
	 * without); failed ones don't.
	 *
	 * @param list<string> $types
	 */
	public function waiting(int $storeId, array $types = []): int {
		return (int) $this->db->value('SELECT COUNT(*) FROM ' . $this->table() . ' WHERE `store_id` = ' . $storeId . ' AND `failed_at` IS NULL' . $this->types($types));
	}

	/**
	 * How many of the store's jobs failed (kept a week).
	 */
	public function failed(int $storeId): int {
		return (int) $this->db->value('SELECT COUNT(*) FROM ' . $this->table() . ' WHERE `store_id` = ' . $storeId . ' AND `failed_at` IS NOT NULL');
	}

	/**
	 * When the oldest due job became due, 0 when none is: the screen warns
	 * when jobs wait and no cron runs them.
	 */
	public function oldestDue(): int {
		$oldest = $this->db->value('SELECT MIN(`run_at`) FROM ' . $this->table() . ' WHERE ' . $this->due());

		return is_string($oldest) ? (int) strtotime($oldest) : 0;
	}

	/**
	 * Runs one of the store's failed jobs again, from its first attempt.
	 */
	public function again(int $storeId, int $id): void {
		$this->db->execute('UPDATE ' . $this->table() . " SET `failed_at` = NULL, `attempts` = 0, `run_at` = '" . date('Y-m-d H:i:s') . "'"
			. ' WHERE `job_id` = ' . $id . ' AND `store_id` = ' . $storeId . ' AND `failed_at` IS NOT NULL');
	}

	/**
	 * Deletes one of the store's jobs that isn't running.
	 */
	public function delete(int $storeId, int $id): void {
		$this->db->execute('DELETE FROM ' . $this->table() . ' WHERE `job_id` = ' . $id . ' AND `store_id` = ' . $storeId
			. " AND (`locked_until` IS NULL OR `locked_until` < '" . date('Y-m-d H:i:s') . "')");
	}

	/**
	 * Drops the store's jobs, of the given types (all without): it disconnected.
	 *
	 * @param list<string> $types
	 */
	public function cancel(int $storeId, array $types = []): void {
		$this->db->execute('DELETE FROM ' . $this->table() . ' WHERE `store_id` = ' . $storeId . $this->types($types));
	}

	private function table(): string {
		return $this->db->table(self::TABLE);
	}

	/**
	 * Due, not failed, not held by a runner.
	 */
	private function due(): string {
		$now = date('Y-m-d H:i:s');

		return "`failed_at` IS NULL AND `run_at` <= '" . $now . "' AND (`locked_until` IS NULL OR `locked_until` < '" . $now . "')";
	}

	/**
	 * @param list<string> $types
	 */
	private function types(array $types): string {
		return $types ? ' AND `type` IN (' . implode(', ', array_map([$this->db, 'quote'], $types)) . ')' : '';
	}
}
