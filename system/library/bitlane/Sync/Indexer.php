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

use Bitlane\OpenCart\Catalog\StoreRegistry;
use Bitlane\OpenCart\Site\Settings;
use Bitlane\OpenCart\Site\Site;
use Bitlane\OpenCart\Site\Stores;
use Bitlane\OpenCart\Sources\Context;
use Bitlane\OpenCart\Sources\Source;
use Bitlane\OpenCart\Sources\Sources;
use Bitlane\OpenCart\Support\BitlaneError;
use Bitlane\OpenCart\Support\Translator;
use Opencart\System\Engine\Registry;

/**
 * Writes a source's items to its index in each of the install's languages,
 * as the other plugins do: their documents are upserted (stamped with the
 * run, with only the picked filters), then what the items no longer have
 * there is deleted. A product's documents share its parent_id, so the ones
 * it had are found with it (a variant removed, the product hidden); any
 * other item has one document, its id. Documents are built in the
 * storefront of the store and language (StoreRegistry), made once a request.
 */
final class Indexer {
	/** @var array<string, Registry> by store and language */
	private array $catalogs = [];

	public function __construct(
		private Sources $sources,
		private Settings $settings,
		private Site $site,
		private Stores $stores,
		private Credentials $credentials,
		private Meilisearch $meilisearch,
		private SyncState $state,
		private ?Registry $app,
	) {
	}

	/**
	 * Syncs a source's items in every language whose index exists (a language
	 * just added has none until Bitlane sets it up; its full sync follows). A
	 * full sync doesn't look for documents an item no longer has: its sweep
	 * deletes them.
	 *
	 * @param list<string> $ids
	 */
	public function sync(int $storeId, string $sourceId, array $ids, string $run, bool $full = false): void {
		$settings = $this->settings->get($storeId);
		$source = $this->sources->get($sourceId);
		if (!$source || !in_array($sourceId, $settings['sources'], true) || !$ids) {
			return;
		}
		$ids = array_values(array_unique(array_map('strval', $ids)));
		// Read before the documents, so a change made meanwhile shows at the next check.
		$changes = $source->changes($storeId, $ids);
		$facets = array_values(array_intersect($settings['facets'], array_column($this->site->facets([$sourceId]), 'attribute')));
		foreach ($this->stores->languages() as $language) {
			try {
				$index = $this->credentials->index($storeId, $sourceId, $language['code']);
			} catch (BitlaneError $error) {
				if ($error->kind === 'no_index') {
					continue;
				}
				throw $error;
			}
			$context = new Context(
				$storeId,
				$language['id'],
				$language['code'],
				$language['opencart'],
				$facets,
				$settings['product_documents'],
				$this->catalog($storeId, $language['opencart']),
				Translator::forLanguage($language['opencart']),
			);
			try {
				$docs = $this->documents($source, $ids, $context, $run);
				$this->meilisearch->add($index, $docs);
				if (!$full) {
					$this->deleteOthers($source, $index, $ids, array_column($docs, 'id'));
				}
			} catch (BitlaneError $error) {
				// The write key may have been replaced: the next try reads it again.
				if ($error->status === 401 || $error->status === 403) {
					$this->credentials->forget($storeId);
				}
				throw $error;
			}
		}
		if (isset($changes['prints'])) {
			$this->state->rememberPrints($storeId, $sourceId, $ids, $changes['prints']);
		}
	}

	/**
	 * The storefront of a store in a language, made once.
	 */
	private function catalog(int $storeId, string $language): Registry {
		if ($this->app === null) {
			throw new BitlaneError('no_catalog', 'Documents are built inside OpenCart only.');
		}

		return $this->catalogs[$storeId . ':' . $language] ??= StoreRegistry::create($this->app, $storeId, $this->stores->url($storeId), $language);
	}

	/**
	 * The items' documents as they're sent: the run, and only the picked filters.
	 *
	 * @param list<string> $ids
	 *
	 * @return list<array<string, mixed>>
	 */
	private function documents(Source $source, array $ids, Context $context, string $run): array {
		$byItem = $source->documents($ids, $context);
		$products = $source->kind() === Source::PRODUCTS;
		$picked = array_flip($context->facets);
		$docs = [];
		foreach ($ids as $item) {
			$itemDocs = array_values(array_filter($byItem[$item] ?? [], 'is_array'));
			foreach ($products ? $itemDocs : array_slice($itemDocs, 0, 1) as $doc) {
				$doc['id'] = $products ? (string) ($doc['id'] ?? $item) : $item;
				if ($products) {
					$doc['parent_id'] = (string) ($doc['parent_id'] ?? $item);
				}
				foreach (['facets', 'product_facets', 'available'] as $field) {
					if (isset($doc[$field])) {
						$doc[$field] = (object) array_intersect_key((array) $doc[$field], $picked);
					}
				}
				$doc['sync_run'] = $run;
				$docs[] = $doc;
			}
		}

		return $docs;
	}

	/**
	 * Deletes what the items had and no longer have. Which of them the index
	 * holds is read first, so most calls are a read, not a write.
	 *
	 * @param array{url: string, key: string, index: string} $index
	 * @param list<string> $ids
	 * @param list<string> $kept the documents just sent
	 */
	private function deleteOthers(Source $source, array $index, array $ids, array $kept): void {
		$products = $source->kind() === Source::PRODUCTS;
		$gone = $products ? $ids : array_values(array_diff($ids, $kept));
		if (!$gone) {
			return;
		}
		if ($products) {
			$quoted = array_map(static fn ($item) => (string) json_encode($item), $ids);
			$existing = $this->meilisearch->documentIds($index, 'parent_id IN [' . implode(', ', $quoted) . ']');
		} else {
			$existing = $this->meilisearch->present($index, false, $gone)['items'];
		}
		$this->meilisearch->delete($index, array_values(array_diff($existing, $kept)));
	}
}
