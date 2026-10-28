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

use Bitlane\OpenCart\Connection\ConnectionStore;
use Bitlane\OpenCart\Queue\Jobs;
use Bitlane\OpenCart\Site\Settings;
use Bitlane\OpenCart\Site\Stores;
use Bitlane\OpenCart\Support\Database;

/**
 * The catalogue's changes, from OpenCart's events (Events): items that
 * changed during a request are collected and, at its end, queued as jobs of
 * 50 (type sync) for every store that syncs, which the runner runs
 * (docs/opencart-plan.md OC9, OC11). What changes many products at once (a
 * category, manufacturer, attribute, option or filter renamed) queues a job
 * that finds them (type products_of), 500 at a time. Products are synced by
 * their masters: a variant's change syncs its product's documents.
 */
final class Changes {
	public const JOB = 'sync';
	public const PRODUCTS_OF = 'products_of';

	// The request's changes, shared: OpenCart makes a new controller (and
	// Plugin) for each event, and one flush queues them all.

	/** @var array<string, array<string, true>> source => item => true */
	private static array $pending = [];

	/** @var list<array{string, int}> what changed many products: kind, id */
	private static array $fanOut = [];

	private static bool $flushing = false;

	public function __construct(
		private Database $db,
		private Jobs $jobs,
		private Indexer $indexer,
		private SyncState $state,
		private Settings $settings,
		private Stores $stores,
		private ConnectionStore $connection,
	) {
	}

	/**
	 * Notes changed items of a source.
	 *
	 * @param array<int|string> $ids as the source's ids() gives them; 0 is none
	 */
	public function add(string $source, array $ids): void {
		foreach ($ids as $id) {
			$id = (string) $id;
			if ($id !== '0' && preg_match('/^[0-9]{1,20}$/', $id)) {
				self::$pending[$source][$id] = true;
			}
		}
		$this->flushAtEnd();
	}

	/**
	 * Notes changed products by their masters (a variant's product, else
	 * itself), read now: called before a product is deleted, its master is
	 * still there to find.
	 *
	 * @param array<int> $ids
	 */
	public function products(array $ids): void {
		$ids = array_values(array_filter(array_map('intval', $ids)));
		if (!$ids) {
			return;
		}
		$masters = array_column($this->db->rows('SELECT `product_id`, `master_id` FROM ' . $this->db->table('product')
			. ' WHERE `product_id` IN (' . implode(',', $ids) . ')'), 'master_id', 'product_id');
		$this->add('product', array_map(static fn (int $id) => (int) ($masters[$id] ?? 0) ?: $id, $ids));
	}

	/**
	 * Something products have changed (a category's name, an option value's):
	 * its products sync, found in the background.
	 *
	 * @param string $kind category, manufacturer, attribute, option, filter or filter_group
	 */
	public function productsOf(string $kind, int $id): void {
		if ($id > 0) {
			self::$fanOut[] = [$kind, $id];
			$this->flushAtEnd();
		}
	}

	/**
	 * Queues the request's changes.
	 */
	public function flush(): void {
		$pending = self::$pending;
		$fanOut = self::$fanOut;
		self::$pending = self::$fanOut = [];
		self::$flushing = false;
		if (!$pending && !$fanOut) {
			return;
		}
		foreach ($this->stores() as $storeId) {
			$chosen = $this->settings->get($storeId)['sources'];
			foreach ($pending as $source => $ids) {
				if (in_array($source, $chosen, true)) {
					foreach (array_chunk(array_keys($ids), 50) as $chunk) {
						$this->jobs->push($storeId, self::JOB, [$source, array_map('strval', $chunk)]);
					}
				}
			}
			if (in_array('product', $chosen, true)) {
				foreach (array_unique($fanOut, SORT_REGULAR) as [$kind, $id]) {
					$this->jobs->push($storeId, self::PRODUCTS_OF, [$kind, $id, 0]);
				}
			}
		}
	}

	/**
	 * A sync job: the items' documents in every language, stamped with the
	 * source's current run.
	 *
	 * @param array<mixed> $args the source, its items
	 */
	public function run(int $storeId, array $args): void {
		$source = (string) ($args[0] ?? '');
		$this->indexer->sync($storeId, $source, array_map('strval', (array) ($args[1] ?? [])), $this->state->run($storeId, $source));
	}

	/**
	 * A products_of job: the masters of 500 of the products something is part
	 * of, queued to sync, then the next 500.
	 *
	 * @param array<mixed> $args the kind, its id, the last product id done
	 */
	public function runProductsOf(int $storeId, array $args): void {
		[$kind, $id, $after] = [(string) ($args[0] ?? ''), (int) ($args[1] ?? 0), (int) ($args[2] ?? 0)];
		$t = [$this->db, 'table'];
		// What has it, and its product id column.
		[$from, $column] = match ($kind) {
			// A category's subtree: its name is in its children's products' values too.
			'category' => [$t('product_to_category') . ' x JOIN ' . $t('category_path') . " cp ON cp.`category_id` = x.`category_id` AND cp.`path_id` = $id WHERE 1", 'x.`product_id`'],
			'manufacturer' => [$t('product') . " x WHERE x.`manufacturer_id` = $id", 'x.`product_id`'],
			'attribute' => [$t('product_attribute') . " x WHERE x.`attribute_id` = $id", 'x.`product_id`'],
			'option' => [$t('product_option') . " x WHERE x.`option_id` = $id", 'x.`product_id`'],
			'filter' => [$t('product_filter') . " x WHERE x.`filter_id` = $id", 'x.`product_id`'],
			'filter_group' => [$t('product_filter') . ' x JOIN ' . $t('filter') . " f ON f.`filter_id` = x.`filter_id` WHERE f.`filter_group_id` = $id", 'x.`product_id`'],
			default => [null, null],
		};
		if ($from === null) {
			return;
		}
		$rows = $this->db->rows("SELECT DISTINCT $column AS product_id FROM $from AND $column > $after ORDER BY $column LIMIT 500");
		$ids = array_map(static fn (array $row) => (int) $row['product_id'], $rows);
		if ($ids) {
			$masters = $this->db->rows('SELECT DISTINCT IF(`master_id` > 0, `master_id`, `product_id`) AS id FROM ' . $t('product') . ' WHERE `product_id` IN (' . implode(',', $ids) . ')');
			foreach (array_chunk(array_map(static fn (array $row) => (string) $row['id'], $masters), 50) as $chunk) {
				$this->jobs->push($storeId, self::JOB, ['product', $chunk]);
			}
		}
		if (count($ids) === 500) {
			$this->jobs->push($storeId, self::PRODUCTS_OF, [$kind, $id, (int) end($ids)]);
		}
	}

	/**
	 * The stores that sync: connected, with a setup Bitlane made ready.
	 *
	 * @return list<int>
	 */
	public function stores(): array {
		if (!$this->connection->connected()) {
			return [];
		}

		return array_values(array_filter(
			array_column($this->stores->all(), 'id'),
			fn (int $storeId) => $this->connection->integrationId($storeId) !== null && !empty($this->state->get($storeId)['sources']),
		));
	}

	private function flushAtEnd(): void {
		if (!self::$flushing) {
			self::$flushing = true;
			register_shutdown_function([$this, 'flush']);
		}
	}
}
