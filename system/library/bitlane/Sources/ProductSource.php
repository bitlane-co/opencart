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

use Bitlane\OpenCart\Documents\ProductDocuments;
use Bitlane\OpenCart\Support\Database;
use Bitlane\OpenCart\Support\Translator;

/**
 * The store's products (ProductDocuments), walked by their masters: a
 * product with variants (4.0's master_id, variants being products of their
 * own) is one item, its variants' documents among its own.
 */
final class ProductSource implements Source {
	public function __construct(private Database $db, private Translator $translator, private ProductDocuments $builder) {
	}

	public function id(): string {
		return 'product';
	}

	public function label(): string {
		return $this->translator->t('Products');
	}

	public function kind(): string {
		return self::PRODUCTS;
	}

	public function count(int $storeId): int {
		return (int) $this->db->value('SELECT COUNT(*) ' . $this->from($storeId));
	}

	/**
	 * Price, availability, categories, brand, each attribute, each filter
	 * group, and each option shoppers pick from a list (select, radio,
	 * checkbox).
	 */
	public function facets(int $languageId, Translator $texts): array {
		$facets = [
			'price' => $texts->t('Price'),
			ProductDocuments::STOCK_STATUS => $texts->t('Availability'),
			'category' => $texts->t('Category'),
			'manufacturer' => $texts->t('Brand'),
		];
		foreach ($this->db->rows('SELECT a.`attribute_id` AS id, d.`name` FROM ' . $this->db->table('attribute') . ' a'
			. ' JOIN ' . $this->db->table('attribute_description') . ' d ON d.`attribute_id` = a.`attribute_id` AND d.`language_id` = ' . $languageId
			. ' ORDER BY a.`sort_order`, d.`name`') as $row) {
			$facets['attribute_' . $row['id']] = html_entity_decode((string) $row['name'], ENT_QUOTES, 'UTF-8');
		}
		foreach ($this->db->rows('SELECT `filter_group_id` AS id, `name` FROM ' . $this->db->table('filter_group_description')
			. ' WHERE `language_id` = ' . $languageId . ' ORDER BY `name`') as $row) {
			$facets['filter_' . $row['id']] = html_entity_decode((string) $row['name'], ENT_QUOTES, 'UTF-8');
		}
		foreach ($this->db->rows('SELECT o.`option_id` AS id, d.`name` FROM ' . $this->db->table('option') . ' o'
			. ' JOIN ' . $this->db->table('option_description') . ' d ON d.`option_id` = o.`option_id` AND d.`language_id` = ' . $languageId
			. " WHERE o.`type` IN ('select', 'radio', 'checkbox') ORDER BY o.`sort_order`, d.`name`") as $row) {
			$facets['option_' . $row['id']] = html_entity_decode((string) $row['name'], ENT_QUOTES, 'UTF-8');
		}

		return $facets;
	}

	public function ids(int $storeId, string $after, int $limit): array {
		$rows = $this->db->rows('SELECT p.`product_id` ' . $this->from($storeId) . ' AND p.`product_id` > ' . (int) $after
			. ' ORDER BY p.`product_id` LIMIT ' . $limit);

		return array_map(static fn (array $row) => (string) $row['product_id'], $rows);
	}

	public function documents(array $ids, Context $context): array {
		return $this->builder->build(array_map('intval', $ids), $context);
	}

	/**
	 * A product's last change is the latest of its own and its variants'.
	 *
	 * @return array{dates: array<array-key, int>}
	 */
	public function changes(int $storeId, array $ids): array {
		if (!$ids) {
			return ['dates' => []];
		}
		$in = implode(',', array_map('intval', $ids));
		$rows = $this->db->rows('SELECT IF(`master_id` > 0, `master_id`, `product_id`) AS id, MAX(`date_modified`) AS modified FROM ' . $this->db->table('product')
			. " WHERE `product_id` IN ($in) OR `master_id` IN ($in) GROUP BY id");

		return ['dates' => array_map(static fn ($date) => (int) strtotime((string) $date), array_column($rows, 'modified', 'id'))];
	}

	/**
	 * The store's products a guest may find, without variants, for a SELECT.
	 */
	private function from(int $storeId): string {
		return 'FROM ' . $this->db->table('product') . ' p'
			. ' JOIN ' . $this->db->table('product_to_store') . ' s ON s.`product_id` = p.`product_id` AND s.`store_id` = ' . $storeId
			. " WHERE p.`master_id` = 0 AND p.`status` = '1' AND p.`date_available` <= NOW()";
	}
}
