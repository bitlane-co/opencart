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

namespace Bitlane\OpenCart\Sync;

use Bitlane\OpenCart\Support\BitlaneError;
use Bitlane\OpenCart\Support\State;

/**
 * Where a store's sync is: a small value of the state table, the same size
 * for any catalogue (items wait in bitlane_job; a full sync's cursor travels
 * in its job's arguments). Per source:
 * - run: stamped on every document of the source written (sync_run). A full
 *   sync starts a new one and, at its end, deletes the source's documents with
 *   another, so what changed meanwhile survives it;
 * - setup: the source's setup it was last synced in full for (Settings::hash());
 * - done, total, started_at, finished_at: its last full sync;
 * the last error, until a sync succeeds; and the engine the store's indexes
 * are on (a new one means syncing everything again, Sync::ready()).
 */
final class SyncState {
	private const NAME = 'sync';

	public function __construct(private State $state) {
	}

	/**
	 * @return array{sources?: array<string, array<string, mixed>>, error?: array{message: string, at: int}, engine?: string}
	 */
	public function get(int $storeId): array {
		return $this->state->json(self::NAME, $storeId);
	}

	/**
	 * The engine the store's indexes are on, as Bitlane last said; empty before.
	 */
	public function engine(int $storeId): string {
		return (string) ($this->get($storeId)['engine'] ?? '');
	}

	public function setEngine(int $storeId, string $engineId): void {
		$state = $this->get($storeId);
		$state['engine'] = $engineId;
		$this->state->setJson(self::NAME, $state, $storeId);
	}

	/**
	 * @return array<string, mixed>
	 */
	public function source(int $storeId, string $source): array {
		return (array) ($this->get($storeId)['sources'][$source] ?? []);
	}

	/**
	 * Merges changes into a source's state; a null value removes the key, and
	 * null for $changes removes the source.
	 *
	 * @param array<string, mixed>|null $changes
	 */
	public function updateSource(int $storeId, string $source, ?array $changes): void {
		$state = $this->get($storeId);
		if ($changes === null) {
			unset($state['sources'][$source]);
		} else {
			$state['sources'][$source] = array_filter(array_merge((array) ($state['sources'][$source] ?? []), $changes), static fn ($value) => $value !== null);
		}
		$this->state->setJson(self::NAME, $state, $storeId);
	}

	/**
	 * A source's current run, started when it has none.
	 */
	public function run(int $storeId, string $source): string {
		$run = (string) ($this->source($storeId, $source)['run'] ?? '');
		if ($run === '') {
			$run = self::newRun();
			$this->updateSource($storeId, $source, ['run' => $run]);
		}

		return $run;
	}

	/**
	 * A run id: short, and fine in a Meilisearch filter.
	 */
	public static function newRun(): string {
		return gmdate('YmdHis') . '-' . bin2hex(random_bytes(3));
	}

	/**
	 * The fingerprints of a source's items as last synced (Source::changes()),
	 * for sources without dates; kept apart from the rest, which is written
	 * on every step.
	 *
	 * @return array<string, string>
	 */
	public function prints(int $storeId, string $source): array {
		return array_map('strval', $this->state->json('prints:' . $source, $storeId));
	}

	/**
	 * Notes the items' fingerprints as synced; an item without one is gone.
	 *
	 * @param list<string> $ids the items synced
	 * @param array<array-key, string> $prints
	 */
	public function rememberPrints(int $storeId, string $source, array $ids, array $prints): void {
		$kept = $this->prints($storeId, $source);
		foreach ($ids as $id) {
			if (isset($prints[$id])) {
				$kept[$id] = $prints[$id];
			} else {
				unset($kept[$id]);
			}
		}
		$this->state->setJson('prints:' . $source, $kept, $storeId);
	}

	/**
	 * Notes a failure, or clears it after a success.
	 */
	public function error(int $storeId, ?BitlaneError $error): void {
		$state = $this->get($storeId);
		if ($error === null && !isset($state['error'])) {
			return;
		}
		if ($error) {
			$state['error'] = ['message' => $error->getMessage(), 'at' => time()];
		} else {
			unset($state['error']);
		}
		$this->state->setJson(self::NAME, $state, $storeId);
	}
}
