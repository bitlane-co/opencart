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
use Bitlane\OpenCart\Site\Stores;
use Bitlane\OpenCart\Sources\Source;
use Bitlane\OpenCart\Sources\Sources;

/**
 * The daily check that each index matches its source, as the other plugins'
 * (docs/opencart-plan.md OC10): what no event saw (imports writing the
 * database directly, other extensions' SQL, jobs that gave up). Each chosen
 * source's ids are walked 1,000 at a time in each language, and Meilisearch
 * says which the index has: missing items sync, and so do items changed
 * since they were synced (a product's date_modified after its documents';
 * for sources without dates, a fingerprint of their rows other than the one
 * kept when they were synced, compared once, in the first language). If the
 * index then holds more documents than the walk found, some are of items
 * gone, and the source's full sync sweeps them. Ten batches a job; a source
 * in a full sync is skipped. It also checks the store's settings.
 */
final class Check {
	public const JOB = 'check';
	public const SOURCE_JOB = 'check_source';

	public function __construct(
		private Sources $sources,
		private Settings $settings,
		private Stores $stores,
		private SyncState $state,
		private Credentials $credentials,
		private Meilisearch $meilisearch,
		private FullSync $full,
		private StoreSettings $storeSettings,
		private Jobs $jobs,
	) {
	}

	/**
	 * Queues the next check: the next 2 AM in OpenCart's time zone, at a
	 * random minute of that hour so stores don't all check at once.
	 */
	public function schedule(int $storeId): void {
		$at = new \DateTimeImmutable('today 02:00');
		if ($at->getTimestamp() <= time()) {
			$at = $at->modify('+1 day');
		}
		$this->jobs->push($storeId, self::JOB, [], $at->getTimestamp() - time() + random_int(0, 59) * 60, 'check:' . $at->format('Ymd'));
	}

	/**
	 * The check job: tomorrow's, the store's settings, then the walk of each
	 * chosen source in each language.
	 */
	public function start(int $storeId): void {
		$this->schedule($storeId);
		$this->storeSettings->check($storeId);
		foreach ($this->settings->get($storeId)['sources'] as $source) {
			foreach ($this->stores->languages() as $language) {
				$this->jobs->push($storeId, self::SOURCE_JOB, [$source, $language['code'], '', 0]);
			}
		}
	}

	/**
	 * Up to ten batches of a source in a language, then the next job; at the
	 * end, the index's documents against those the walk found.
	 *
	 * @param array<mixed> $args the source, the language, the last id checked, documents found so far
	 */
	public function batch(int $storeId, array $args): void {
		[$sourceId, $language, $after, $documents] = [(string) ($args[0] ?? ''), (string) ($args[1] ?? ''), (string) ($args[2] ?? ''), (int) ($args[3] ?? 0)];
		$source = $this->sources->get($sourceId);
		$state = $this->state->source($storeId, $sourceId);
		if (!$source || !in_array($sourceId, $this->settings->get($storeId)['sources'], true) || (isset($state['started_at']) && empty($state['finished_at']))) {
			return;
		}
		$index = $this->credentials->index($storeId, $sourceId, $language);
		$first = strcasecmp($language, (string) ($this->stores->languages()[0]['code'] ?? '')) === 0;
		$kept = $this->state->prints($storeId, $sourceId);
		for ($i = 0; $i < 10; $i++) {
			$ids = $source->ids($storeId, $after, 1000);
			if (!$ids) {
				if ($this->meilisearch->count($index) > $documents) {
					$this->full->start($storeId, $sourceId);
				}

				return;
			}
			$present = $this->meilisearch->present($index, $source->kind() === Source::PRODUCTS, $ids);
			$changes = $source->changes($storeId, $present['items']);
			$stale = [];
			foreach ($changes['dates'] ?? [] as $item => $changed) {
				if ($changed > ($present['modified'][(string) $item] ?? 0)) {
					$stale[] = (string) $item;
				}
			}
			if ($first) {
				foreach ($changes['prints'] ?? [] as $item => $print) {
					if ($print !== ($kept[(string) $item] ?? '')) {
						$stale[] = (string) $item;
						// A category's or brand's name is in its products' filter values too.
						if (isset($kept[(string) $item]) && in_array($sourceId, ['category', 'manufacturer'], true)) {
							$this->jobs->push($storeId, Changes::PRODUCTS_OF, [$sourceId, (int) $item, 0]);
						}
					}
				}
			}
			foreach (array_chunk(array_values(array_unique(array_merge(array_diff($ids, $present['items']), $stale))), 50) as $chunk) {
				$this->jobs->push($storeId, Changes::JOB, [$sourceId, $chunk]);
			}
			$documents += $present['documents'];
			$after = (string) end($ids);
		}
		$this->jobs->push($storeId, self::SOURCE_JOB, [$sourceId, $language, $after, $documents]);
	}
}
