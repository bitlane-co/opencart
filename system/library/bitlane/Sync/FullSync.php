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
use Bitlane\OpenCart\Site\Site;
use Bitlane\OpenCart\Site\Stores;
use Bitlane\OpenCart\Sources\Source;
use Bitlane\OpenCart\Sources\Sources;
use Bitlane\OpenCart\Support\BitlaneError;

/**
 * A source's full sync in a store, as jobs (type full_sync) one after
 * another, each a batch of items (50 products, 200 of anything else) written
 * in every language, its arguments the cursor (source, run, last id), so a
 * retry redoes exactly that batch (docs/opencart-plan.md OC11). Documents
 * are stamped with a new run; once the source's items are all sent, each
 * language's index loses the documents of another run, which removes what's
 * gone since while the index stays searchable throughout.
 */
final class FullSync {
	public const JOB = 'full_sync';

	public function __construct(
		private Indexer $indexer,
		private Sources $sources,
		private Settings $settings,
		private Site $site,
		private Stores $stores,
		private SyncState $state,
		private Credentials $credentials,
		private Meilisearch $meilisearch,
		private Jobs $jobs,
	) {
	}

	/**
	 * Starts a source's full sync; one still running stops (its jobs see a
	 * newer run and end).
	 */
	public function start(int $storeId, string $source): string {
		$run = SyncState::newRun();
		$counts = array_column($this->site->sources($storeId), 'count', 'id');
		$this->state->updateSource($storeId, $source, [
			'run' => $run,
			'done' => 0,
			'total' => (int) ($counts[$source] ?? 0),
			'started_at' => time(),
			'finished_at' => null,
		]);
		$this->jobs->push($storeId, self::JOB, [$source, $run, '']);

		return $run;
	}

	/**
	 * One batch, then the next is queued.
	 *
	 * @param array<mixed> $args the source, the run, the last id done
	 */
	public function step(int $storeId, array $args): void {
		[$sourceId, $run, $after] = array_map('strval', $args + ['', '', '']);
		$state = $this->state->source($storeId, $sourceId);
		$source = $this->sources->get($sourceId);
		if (($state['run'] ?? '') !== $run || !empty($state['finished_at']) || !$source || !in_array($sourceId, $this->settings->get($storeId)['sources'], true)) {
			return;
		}
		$ids = $source->ids($storeId, $after, $source->kind() === Source::PRODUCTS ? 50 : 200);
		if (!$ids) {
			$this->sweep($storeId, $sourceId, $run);
			$this->state->updateSource($storeId, $sourceId, ['finished_at' => time()]);

			return;
		}
		$this->indexer->sync($storeId, $sourceId, $ids, $run, true);
		$this->state->updateSource($storeId, $sourceId, ['done' => (int) ($state['done'] ?? 0) + count($ids)]);
		$this->jobs->push($storeId, self::JOB, [$sourceId, $run, (string) end($ids)]);
	}

	/**
	 * Deletes the source's documents of other runs in each language's index.
	 */
	private function sweep(int $storeId, string $source, string $run): void {
		foreach ($this->stores->languages() as $language) {
			try {
				$index = $this->credentials->index($storeId, $source, $language['code']);
			} catch (BitlaneError $error) {
				if ($error->kind === 'no_index') {
					continue;
				}
				throw $error;
			}
			$this->meilisearch->deleteWhere($index, 'sync_run != "' . $run . '"');
		}
	}
}
