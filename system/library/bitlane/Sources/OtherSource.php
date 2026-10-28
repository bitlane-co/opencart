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

namespace Bitlane\OpenCart\Sources;

use Bitlane\OpenCart\Support\Database;
use Bitlane\OpenCart\Support\Translator;

/**
 * A source of "other" documents (widget_public.OtherDocumentV1): the store's
 * items of a table (in its <table>_to_store), walked by id, each one
 * document whose id is the item's.
 */
abstract class OtherSource implements Source {
	public function __construct(protected Database $db, protected Translator $translator) {
	}

	/**
	 * The item's table (without prefix), its id column, and what else a guest
	 * needs of it (SQL on the table as t).
	 *
	 * @return array{table: string, key: string, where: string}
	 */
	abstract protected function table(): array;

	/**
	 * The item's document in the context's store and language; null when a
	 * guest can't find it.
	 *
	 * @return array<string, mixed>|null
	 */
	abstract protected function document(int $id, Context $context): ?array;

	public function kind(): string {
		return self::OTHER;
	}

	public function count(int $storeId): int {
		return (int) $this->db->value('SELECT COUNT(*) ' . $this->from($storeId));
	}

	public function facets(int $languageId, Translator $texts): array {
		return [];
	}

	public function ids(int $storeId, string $after, int $limit): array {
		['key' => $key] = $this->table();
		$rows = $this->db->rows('SELECT t.`' . $key . '` AS id ' . $this->from($storeId) . ' AND t.`' . $key . '` > ' . (int) $after
			. ' ORDER BY t.`' . $key . '` LIMIT ' . $limit);

		return array_map(static fn (array $row) => (string) $row['id'], $rows);
	}

	public function documents(array $ids, Context $context): array {
		$documents = [];
		foreach ($ids as $id) {
			$doc = $this->document((int) $id, $context);
			$documents[$id] = $doc === null ? [] : [array_filter($doc + ['id' => $id], static fn ($value) => $value !== null && $value !== '' && $value !== [])];
		}

		return $documents;
	}

	/**
	 * A fingerprint of each item's rows: the item's, its descriptions and its
	 * SEO URLs, in every language.
	 *
	 * @return array{prints: array<array-key, string>}
	 */
	public function changes(int $storeId, array $ids): array {
		if (!$ids) {
			return ['prints' => []];
		}
		['table' => $table, 'key' => $key] = $this->table();
		$in = implode(',', array_map('intval', $ids));
		$rows = [];
		foreach ($this->db->rows('SELECT * FROM ' . $this->db->table($table) . " WHERE `$key` IN ($in)") as $row) {
			$rows[(string) $row[$key]][] = $row;
		}
		if ($this->db->exists($table . '_description')) {
			foreach ($this->db->rows('SELECT * FROM ' . $this->db->table($table . '_description') . " WHERE `$key` IN ($in) ORDER BY `language_id`") as $row) {
				$rows[(string) $row[$key]][] = $row;
			}
		}
		// A category's is under its path ("20_27"), the category last.
		$seoKey = $key === 'category_id' ? 'path' : $key;
		foreach ($this->db->rows('SELECT `value`, `language_id`, `keyword` FROM ' . $this->db->table('seo_url') . ' WHERE `store_id` = ' . $storeId
			. ' AND `key` = ' . $this->db->quote($seoKey) . " AND SUBSTRING_INDEX(`value`, '_', -1) IN ('" . implode("','", array_map('intval', $ids)) . "') ORDER BY `language_id`") as $row) {
			$item = (string) substr((string) strrchr('_' . $row['value'], '_'), 1);
			if (isset($rows[$item])) {
				$rows[$item][] = $row;
			}
		}

		return ['prints' => array_map(static fn (array $itemRows) => md5((string) json_encode($itemRows)), $rows)];
	}

	/**
	 * The store's items a guest may find, for a SELECT.
	 */
	protected function from(int $storeId): string {
		['table' => $table, 'key' => $key, 'where' => $where] = $this->table();

		return 'FROM ' . $this->db->table($table) . ' t'
			. ' JOIN ' . $this->db->table($table . '_to_store') . ' s ON s.`' . $key . '` = t.`' . $key . '` AND s.`store_id` = ' . $storeId
			. ' WHERE ' . $where;
	}

	/**
	 * The item's row, with its description in the context's language when the
	 * table has them; null when a guest can't find it in the store.
	 *
	 * @return array<string, mixed>|null
	 */
	protected function row(int $id, Context $context, bool $described = true): ?array {
		['table' => $table, 'key' => $key] = $this->table();
		$sql = 'SELECT t.*' . ($described ? ', d.*' : '') . ' ' . $this->from($context->storeId) . ' AND t.`' . $key . '` = ' . $id;
		if ($described) {
			$sql = str_replace(' WHERE ', ' JOIN ' . $this->db->table($table . '_description') . ' d ON d.`' . $key . '` = t.`' . $key . '` AND d.`language_id` = ' . $context->languageId . ' WHERE ', $sql);
		}

		return $this->db->row($sql);
	}
}
