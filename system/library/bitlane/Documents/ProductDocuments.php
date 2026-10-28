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

namespace Bitlane\OpenCart\Documents;

use Bitlane\OpenCart\Site\Settings;
use Bitlane\OpenCart\Sources\Context;
use Bitlane\OpenCart\Support\Database;

/**
 * Products as documents (widget_public.ProductDocumentV1, docs/opencart-plan.md
 * Part 3), as a guest sees them in the context's store and language: each
 * product read with OpenCart's own catalog model (prices as the storefront
 * shows them, specials and discounts included, whatever the version), the
 * rest for a whole batch in a few queries. A product with variants (products
 * whose master_id is it) gives a document per variant too (per_variation),
 * or one with all their values (per_product).
 */
final class ProductDocuments {
	public const STOCK_STATUS = 'stock_status';

	/** @var array<int, string> manufacturer names by id (one name in every language) */
	private array $manufacturers = [];

	public function __construct(private Database $db) {
	}

	/**
	 * @param list<int> $masterIds
	 *
	 * @return array<array-key, list<array<string, mixed>>> by master id
	 */
	public function build(array $masterIds, Context $context): array {
		$perVariation = $context->productDocuments === Settings::PER_VARIATION;
		$family = $this->family($masterIds, $context->storeId);
		$all = array_merge(...array_values($family ?: [[]]));
		$model = $context->model('catalog/product');
		$products = [];
		foreach ($all as $id) {
			$row = $model->getProduct($id);
			if (is_array($row) && $row) {
				$products[$id] = $row;
			}
		}
		$facets = $this->facets(array_keys($products), $products, $context);
		$codes = $this->codes(array_keys($products), $products);
		$sales = $this->sales(array_keys($products));
		$variantTitles = $this->variantTitles($products, $context);

		$documents = [];
		foreach ($masterIds as $masterId) {
			$documents[(string) $masterId] = [];
			$master = $products[$masterId] ?? null;
			if ($master === null) {
				continue;
			}
			$members = array_values(array_filter($family[$masterId] ?? [], static fn (int $id) => isset($products[$id])));
			$productFacets = Fields::merge(...array_map(static fn (int $id) => $facets[$id] ?? [], $members));
			$available = [];
			foreach ($members as $id) {
				if ((int) $products[$id]['quantity'] > 0) {
					$available = Fields::merge($available, $facets[$id] ?? []);
				}
			}
			foreach ($perVariation ? $members : [$masterId] as $id) {
				$doc = $this->document($products[$id], $master, $context, $codes[$id] ?? [], $sales[$id] ?? 0, $variantTitles[$id] ?? null);
				if ($perVariation) {
					$doc['facets'] = (object) ($facets[$id] ?? []);
					$doc['available'] = (object) ((int) $products[$id]['quantity'] > 0 ? ($facets[$id] ?? []) : []);
				} else {
					// The whole product: every variant's values, prices and stock.
					$doc['facets'] = (object) $productFacets;
					$doc['available'] = (object) $available;
					$doc['flags']['in_stock'] = $available !== [];
					$prices = array_values(array_filter(array_map(fn (int $member) => $this->prices($products[$member], $context), $members)));
					if ($prices) {
						$doc['price'] = ['min' => min(array_column($prices, 'now')), 'max' => max(array_column($prices, 'now'))];
						$doc['regular_price'] = ['min' => min(array_column($prices, 'regular')), 'max' => max(array_column($prices, 'regular'))];
						$doc['flags']['on_sale'] = in_array(true, array_column($prices, 'on_sale'), true);
					}
				}
				$doc['product_facets'] = (object) $productFacets;
				$documents[(string) $masterId][] = array_filter($doc, static fn ($value) => $value !== null && $value !== '' && $value !== []);
			}
		}

		return $documents;
	}

	/**
	 * One product's document, the facets set by build().
	 *
	 * @param array<string, mixed> $row getProduct()'s
	 * @param array<string, mixed> $master the product it's a variant of (itself when it isn't one)
	 * @param list<string> $codes
	 *
	 * @return array<string, mixed>
	 */
	private function document(array $row, array $master, Context $context, array $codes, int $sales, ?string $variantTitle): array {
		$id = (int) $row['product_id'];
		$masterId = (int) $master['product_id'];
		$variant = $id !== $masterId;
		$title = Fields::plain((string) $master['name']);
		$prices = $this->prices($row, $context);
		$inStock = (int) $row['quantity'] > 0;

		return [
			'id' => (string) $id,
			'parent_id' => (string) $masterId,
			'title' => $title,
			'sku' => Fields::plain((string) $master['model']),
			'variant_title' => $variant ? ($variantTitle ?? Fields::plain((string) $row['name'])) : null,
			'variant_sku' => $variant ? Fields::plain((string) $row['model']) : null,
			'codes' => $codes,
			'permalink' => $context->link('product/product', 'product_id=' . $id),
			'image' => $context->image((string) ($row['image'] !== '' ? $row['image'] : $master['image']), 'product', $variant && $variantTitle ? $title . ' – ' . $variantTitle : $title),
			'brand' => $this->manufacturer((int) $row['manufacturer_id']),
			'price' => $prices === null ? null : ['min' => $prices['now'], 'max' => $prices['now']],
			'regular_price' => $prices === null ? null : ['min' => $prices['regular'], 'max' => $prices['regular']],
			'short_description' => Fields::text((string) $row['meta_description'], 300),
			'description' => Fields::text((string) $row['description']),
			'flags' => ['in_stock' => $inStock, 'on_sale' => $prices['on_sale'] ?? false, 'featured' => false],
			'total_sales' => $sales,
			'rating' => (float) $row['rating'],
			'rating_count' => (int) $row['reviews'],
			'created_at' => (int) strtotime((string) $row['date_added']),
			'menu_order' => (int) $row['sort_order'],
			'modified_at' => (int) strtotime((string) $row['date_modified']),
		];
	}

	/**
	 * What a guest pays (the special while there is one) and the price before
	 * it, with tax as the store shows prices (config_tax), in the store's
	 * currency's minor unit; null when the store shows prices to customers
	 * who log in only (config_customer_price).
	 *
	 * @param array<string, mixed> $row getProduct()'s: price is the quantity-1 discount, else the price
	 *
	 * @return array{now: int, regular: int, on_sale: bool}|null
	 */
	private function prices(array $row, Context $context): ?array {
		if ($context->config('config_customer_price')) {
			return null;
		}
		$tax = $context->catalog->get('tax');
		$currency = $context->catalog->get('currency');
		$code = (string) $context->config('config_currency');
		$shown = static fn (float $amount): int => Fields::minor(
			(float) $currency->format($tax->calculate($amount, (int) $row['tax_class_id'], (bool) $context->config('config_tax')), $code, 0.0, false),
			$code,
		);
		$regular = $shown((float) $row['price']);
		$now = (float) $row['special'] > 0 ? $shown((float) $row['special']) : $regular;

		return ['now' => $now, 'regular' => $regular, 'on_sale' => $now < $regular];
	}

	/**
	 * The masters' visible products in the store: each master first, then its
	 * variants.
	 *
	 * @param list<int> $masterIds
	 *
	 * @return array<int, list<int>>
	 */
	private function family(array $masterIds, int $storeId): array {
		if (!$masterIds) {
			return [];
		}
		$in = implode(',', $masterIds);
		$family = [];
		foreach ($this->db->rows('SELECT p.`product_id`, p.`master_id` FROM ' . $this->db->table('product') . ' p'
			. ' JOIN ' . $this->db->table('product_to_store') . ' s ON s.`product_id` = p.`product_id` AND s.`store_id` = ' . $storeId
			. ' WHERE (p.`product_id` IN (' . $in . ') OR p.`master_id` IN (' . $in . '))'
			. " AND p.`status` = '1' AND p.`date_available` <= NOW() ORDER BY p.`master_id`, p.`product_id`") as $row) {
			$master = (int) $row['master_id'] ?: (int) $row['product_id'];
			$family[$master][] = (int) $row['product_id'];
		}

		return $family;
	}

	/**
	 * Each product's filter values (Fields::value()): its categories in the
	 * store with the ones above them, its manufacturer, attributes, filters,
	 * the option values it has in stock, and its availability.
	 *
	 * @param list<int> $ids
	 * @param array<int, array<string, mixed>> $products
	 *
	 * @return array<int, array<string, list<string>>>
	 */
	private function facets(array $ids, array $products, Context $context): array {
		$facets = [];
		if (!$ids) {
			return $facets;
		}
		$in = implode(',', $ids);
		$language = $context->languageId;

		foreach ($this->db->rows('SELECT DISTINCT pc.`product_id`, cp.`path_id`, d.`name` FROM ' . $this->db->table('product_to_category') . ' pc'
			. ' JOIN ' . $this->db->table('category_path') . ' cp ON cp.`category_id` = pc.`category_id`'
			. ' JOIN ' . $this->db->table('category') . " c ON c.`category_id` = cp.`path_id` AND c.`status` = '1'"
			. ' JOIN ' . $this->db->table('category_to_store') . ' cs ON cs.`category_id` = cp.`path_id` AND cs.`store_id` = ' . $context->storeId
			. ' JOIN ' . $this->db->table('category_description') . ' d ON d.`category_id` = cp.`path_id` AND d.`language_id` = ' . $language
			. ' WHERE pc.`product_id` IN (' . $in . ')') as $row) {
			$facets[(int) $row['product_id']]['category'][] = Fields::value((string) $row['name'], '', (int) $row['path_id']);
		}

		foreach ($products as $id => $row) {
			$name = $this->manufacturer((int) $row['manufacturer_id']);
			if ($name !== '') {
				$facets[$id]['manufacturer'][] = Fields::value($name, '', (int) $row['manufacturer_id']);
			}
			$facets[$id][self::STOCK_STATUS][] = $this->stockStatus($row, $context);
		}

		foreach ($this->db->rows('SELECT `product_id`, `attribute_id`, `text` FROM ' . $this->db->table('product_attribute')
			. ' WHERE `language_id` = ' . $language . ' AND `product_id` IN (' . $in . ')') as $row) {
			$text = Fields::plain((string) $row['text']);
			if ($text !== '') {
				$facets[(int) $row['product_id']]['attribute_' . $row['attribute_id']][] = Fields::value($text, '', Fields::slug($text));
			}
		}

		foreach ($this->db->rows('SELECT pf.`product_id`, f.`filter_group_id`, f.`filter_id`, d.`name` FROM ' . $this->db->table('product_filter') . ' pf'
			. ' JOIN ' . $this->db->table('filter') . ' f ON f.`filter_id` = pf.`filter_id`'
			. ' JOIN ' . $this->db->table('filter_description') . ' d ON d.`filter_id` = f.`filter_id` AND d.`language_id` = ' . $language
			. ' WHERE pf.`product_id` IN (' . $in . ')') as $row) {
			$facets[(int) $row['product_id']]['filter_' . $row['filter_group_id']][] = Fields::value((string) $row['name'], '', (int) $row['filter_id']);
		}

		$base = (string) $context->config('config_url');
		foreach ($this->db->rows('SELECT pov.`product_id`, pov.`option_id`, pov.`option_value_id`, d.`name`, v.`image` FROM ' . $this->db->table('product_option_value') . ' pov'
			. ' JOIN ' . $this->db->table('option') . " o ON o.`option_id` = pov.`option_id` AND o.`type` IN ('select', 'radio', 'checkbox')"
			. ' JOIN ' . $this->db->table('option_value_description') . ' d ON d.`option_value_id` = pov.`option_value_id` AND d.`language_id` = ' . $language
			. ' LEFT JOIN ' . $this->db->table('option_value') . ' v ON v.`option_value_id` = pov.`option_value_id`'
			. ' WHERE pov.`product_id` IN (' . $in . ") AND (pov.`quantity` > 0 OR pov.`subtract` = '0')") as $row) {
			$image = (string) ($row['image'] ?? '');
			$facets[(int) $row['product_id']]['option_' . $row['option_id']][] = Fields::value((string) $row['name'], '', (int) $row['option_value_id'], $image !== '' ? $base . 'image/' . $image : '');
		}

		return array_map(static fn (array $byAttribute) => array_map(static fn (array $values) => array_values(array_unique($values)), $byAttribute), $facets);
	}

	/**
	 * In stock, on backorder (OpenCart sells what's out of stock when
	 * config_stock_checkout is on) or out of stock with the product's stock
	 * status ("2-3 Days", "Pre-Order").
	 *
	 * @param array<string, mixed> $row
	 */
	private function stockStatus(array $row, Context $context): string {
		if ((int) $row['quantity'] > 0) {
			return Fields::value($context->texts->t('In stock'), 'instock', 'instock');
		}
		$status = (string) $this->db->value('SELECT `name` FROM ' . $this->db->table('stock_status')
			. ' WHERE `stock_status_id` = ' . (int) $row['stock_status_id'] . ' AND `language_id` = ' . $context->languageId);
		if ($context->config('config_stock_checkout')) {
			return Fields::value($status !== '' ? $status : $context->texts->t('On backorder'), 'onbackorder', 'onbackorder');
		}

		return Fields::value($status !== '' ? $status : $context->texts->t('Out of stock'), 'outofstock', 'outofstock');
	}

	/**
	 * Codes shoppers type: SKU, UPC, EAN, JAN, ISBN and MPN, on the product
	 * (4.0 to 4.1.0.3) or in product_code (4.1.0.3 and later).
	 *
	 * @param list<int> $ids
	 * @param array<int, array<string, mixed>> $products
	 *
	 * @return array<int, list<string>>
	 */
	private function codes(array $ids, array $products): array {
		$codes = [];
		foreach ($products as $id => $row) {
			foreach (['sku', 'upc', 'ean', 'jan', 'isbn', 'mpn'] as $column) {
				if (isset($row[$column]) && trim((string) $row[$column]) !== '') {
					$codes[$id][] = Fields::plain((string) $row[$column]);
				}
			}
		}
		if ($ids && $this->db->exists('product_code')) {
			foreach ($this->db->rows('SELECT `product_id`, `value` FROM ' . $this->db->table('product_code') . ' WHERE `product_id` IN (' . implode(',', $ids) . ')') as $row) {
				if (trim((string) $row['value']) !== '') {
					$codes[(int) $row['product_id']][] = Fields::plain((string) $row['value']);
				}
			}
		}

		return array_map(static fn (array $list) => array_values(array_unique($list)), $codes);
	}

	/**
	 * Units sold in orders that were placed (any status but "missing").
	 *
	 * @param list<int> $ids
	 *
	 * @return array<int, int>
	 */
	private function sales(array $ids): array {
		if (!$ids) {
			return [];
		}
		$rows = $this->db->rows('SELECT op.`product_id`, SUM(op.`quantity`) AS sold FROM ' . $this->db->table('order_product') . ' op'
			. ' JOIN ' . $this->db->table('order') . ' o ON o.`order_id` = op.`order_id` AND o.`order_status_id` > 0'
			. ' WHERE op.`product_id` IN (' . implode(',', $ids) . ') GROUP BY op.`product_id`');

		return array_map('intval', array_column($rows, 'sold', 'product_id'));
	}

	/**
	 * A variant's option values ("Red, XL"), from its variant field:
	 * product_option_id => the product_option_value_id (or several, or text).
	 *
	 * @param array<int, array<string, mixed>> $products
	 *
	 * @return array<int, string>
	 */
	private function variantTitles(array $products, Context $context): array {
		$chosen = [];
		foreach ($products as $id => $row) {
			$variant = is_array($row['variant'] ?? null) ? $row['variant'] : (json_decode((string) ($row['variant'] ?? ''), true) ?: []);
			if ((int) $row['master_id'] > 0 && is_array($variant)) {
				array_walk_recursive($variant, static function ($value) use (&$chosen, $id): void {
					$chosen[$id][] = (string) $value;
				});
			}
		}
		$valueIds = array_map('intval', array_filter(array_merge(...array_values($chosen ?: [[]])), 'ctype_digit'));
		$names = [];
		if ($valueIds) {
			$names = array_column($this->db->rows('SELECT pov.`product_option_value_id` AS id, d.`name` FROM ' . $this->db->table('product_option_value') . ' pov'
				. ' JOIN ' . $this->db->table('option_value_description') . ' d ON d.`option_value_id` = pov.`option_value_id` AND d.`language_id` = ' . $context->languageId
				. ' WHERE pov.`product_option_value_id` IN (' . implode(',', array_unique($valueIds)) . ')'), 'name', 'id');
		}

		return array_filter(array_map(static fn (array $values) => implode(', ', array_map(
			static fn (string $value) => Fields::plain((string) ($names[(int) $value] ?? (ctype_digit($value) ? '' : $value))),
			$values,
		)), $chosen), static fn (string $title) => trim($title, ', ') !== '');
	}

	private function manufacturer(int $id): string {
		if ($id <= 0) {
			return '';
		}

		return $this->manufacturers[$id] ??= Fields::plain((string) $this->db->value('SELECT `name` FROM ' . $this->db->table('manufacturer') . ' WHERE `manufacturer_id` = ' . $id));
	}
}
