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

use Bitlane\OpenCart\Queue\Jobs;
use Bitlane\OpenCart\Site\Settings;

/**
 * A store's sync as the Bitlane screen sees it: when a source syncs in full
 * (Bitlane made a setup ready that changed it; Resync), where each is, and
 * stopping it all when the store disconnects.
 */
final class Sync {
	public function __construct(
		private FullSync $full,
		private SyncState $state,
		private Settings $settings,
		private Credentials $credentials,
		private Check $check,
		private Dates $dates,
		private StoreSettings $storeSettings,
		private Jobs $jobs,
	) {
	}

	/**
	 * Bitlane made the store's setup ready: each source the setup changed
	 * (new, other filters, languages, the products' mode) syncs in full with
	 * fresh credentials, and every source when the store moved to another
	 * engine ($engineId, the site's in Bitlane: the new one's indexes start
	 * empty); sources no longer chosen are forgotten (Bitlane deleted their
	 * indexes).
	 */
	public function ready(int $storeId, string $engineId = ''): void {
		// Once a day each, from now on: one already queued is kept.
		$this->check->schedule($storeId);
		$this->dates->schedule($storeId);
		$chosen = $this->settings->get($storeId)['sources'];
		// Another engine (the setup moved the store): its indexes start empty.
		$moved = $engineId !== '' && !in_array($this->state->engine($storeId), ['', $engineId], true);
		if ($engineId !== '' && $this->state->engine($storeId) !== $engineId) {
			$this->state->setEngine($storeId, $engineId);
		}
		$changed = $moved ? $chosen : array_values(array_filter($chosen, fn ($source) => ($this->state->source($storeId, $source)['setup'] ?? '') !== $this->settings->hash($storeId, $source)));
		foreach (array_diff(array_keys($this->state->get($storeId)['sources'] ?? []), $chosen) as $removed) {
			$this->state->updateSource($storeId, (string) $removed, null);
		}
		if (!$changed) {
			return;
		}
		$this->credentials->forget($storeId);
		$this->storeSettings->remember($storeId);
		foreach ($changed as $source) {
			$this->full->start($storeId, $source);
			$this->state->updateSource($storeId, $source, ['setup' => $this->settings->hash($storeId, $source)]);
		}
	}

	/**
	 * Syncs a source again, or every chosen one.
	 */
	public function resync(int $storeId, ?string $source = null): void {
		$chosen = $this->settings->get($storeId)['sources'];
		$this->credentials->forget($storeId);
		foreach ($source === null ? $chosen : array_intersect([$source], $chosen) as $id) {
			$this->full->start($storeId, $id);
		}
	}

	/**
	 * Where each chosen source is, the changes waiting, and the last error.
	 *
	 * @return array{sources: list<array{id: string, running: bool, done: int, total: int, started_at: ?int, finished_at: ?int}>, queued: int, error: array{message: string, at: int}|null}
	 */
	public function summary(int $storeId): array {
		$state = $this->state->get($storeId);
		$sources = [];
		foreach ($this->settings->get($storeId)['sources'] as $id) {
			$source = (array) ($state['sources'][$id] ?? []);
			$sources[] = [
				'id' => $id,
				'running' => isset($source['started_at']) && empty($source['finished_at']),
				'done' => (int) ($source['done'] ?? 0),
				'total' => (int) ($source['total'] ?? 0),
				'started_at' => isset($source['started_at']) ? (int) $source['started_at'] : null,
				'finished_at' => isset($source['finished_at']) ? (int) $source['finished_at'] : null,
			];
		}

		return [
			'sources' => $sources,
			'queued' => $this->jobs->waiting($storeId, [Changes::JOB, Changes::PRODUCTS_OF]),
			'error' => $state['error'] ?? null,
		];
	}
}
