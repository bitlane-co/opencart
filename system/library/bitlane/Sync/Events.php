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

use Bitlane\OpenCart\Platform\Version;
use Bitlane\OpenCart\Support\Database;

/**
 * What OpenCart's model events mean for the search (docs/opencart-plan.md
 * OC9): the admin's catalogue models (products and their variants,
 * categories, manufacturers, information pages, reviews, attributes, options,
 * filters, SEO URLs) and the storefront's order history, whose stock changes
 * are SQL without an event of their own. Routes come with slashes
 * ("catalog/product/editProduct"), whatever the version wrote.
 */
final class Events {
	public function __construct(private Database $db, private Changes $changes, private Version $version) {
	}

	/**
	 * Before a model method: what a deletion takes away must be read while
	 * it's there.
	 *
	 * @param array<mixed> $args
	 */
	public function before(string $route, array $args): void {
		$id = (int) ($args[0] ?? 0);
		switch ($route) {
			case 'catalog/product/deleteProduct':
				$this->changes->products([$id]);
				break;
			case 'catalog/review/deleteReview':
				$this->changes->products([(int) $this->db->value('SELECT `product_id` FROM ' . $this->db->table('review') . ' WHERE `review_id` = ' . $id)]);
				break;
			case 'design/seo_url/deleteSeoUrl':
				$this->seoUrl($id);
				break;
		}
	}

	/**
	 * After a model method.
	 *
	 * @param array<mixed> $args
	 */
	public function after(string $route, array $args, mixed $output): void {
		$id = (int) ($args[0] ?? 0);
		$created = is_numeric($output) ? (int) $output : 0;
		switch ($route) {
			case 'catalog/product/addProduct':
			case 'catalog/product/copyProduct':
				$this->changes->products([$created]);
				break;
			case 'catalog/product/editProduct':
			case 'catalog/product/editRating':
			case 'catalog/product/editVariants':
				$this->changes->products([$id]);
				break;
			case 'catalog/product/addVariant':
				$this->changes->products([$id, $created]);
				break;
			case 'catalog/product/editVariant':
			case 'catalog/product/editMasterId':
				$this->changes->products([$id, (int) ($args[1] ?? 0)]);
				break;
			case 'catalog/category/addCategory':
			case 'catalog/category/editCategory':
			case 'catalog/category/deleteCategory':
				$category = $created ?: $id;
				$this->changes->add('category', [$category]);
				$this->changes->productsOf('category', $category);
				break;
			case 'catalog/manufacturer/addManufacturer':
			case 'catalog/manufacturer/editManufacturer':
			case 'catalog/manufacturer/deleteManufacturer':
				$manufacturer = $created ?: $id;
				$this->changes->add('manufacturer', [$manufacturer]);
				$this->changes->productsOf('manufacturer', $manufacturer);
				break;
			case 'catalog/information/addInformation':
			case 'catalog/information/editInformation':
			case 'catalog/information/deleteInformation':
				$this->changes->add('information', [$created ?: $id]);
				break;
			case 'catalog/review/addReview':
				$this->changes->products([(int) (is_array($args[0] ?? null) ? ($args[0]['product_id'] ?? 0) : 0)]);
				break;
			case 'catalog/review/editReview':
				// The product it's about now, and the one it was about (it may have moved).
				$this->changes->products([
					(int) (is_array($args[1] ?? null) ? ($args[1]['product_id'] ?? 0) : 0),
					(int) $this->db->value('SELECT `product_id` FROM ' . $this->db->table('review') . ' WHERE `review_id` = ' . $id),
				]);
				break;
			case 'catalog/attribute/editAttribute':
			case 'catalog/attribute/deleteAttribute':
				$this->changes->productsOf('attribute', $id);
				break;
			case 'catalog/option/editOption':
			case 'catalog/option/deleteOption':
				$this->changes->productsOf('option', $id);
				break;
			case 'catalog/filter/editFilter':
			case 'catalog/filter/deleteFilter':
				// 4.0's filter model edits a group with its filters; 4.1's one filter.
				$this->changes->productsOf($this->version->atLeast41() ? 'filter' : 'filter_group', $id);
				break;
			case 'catalog/filter_group/editFilterGroup':
			case 'catalog/filter_group/deleteFilterGroup':
				$this->changes->productsOf('filter_group', $id);
				break;
			case 'design/seo_url/addSeoUrl':
				$this->seoUrl($created);
				break;
			case 'design/seo_url/editSeoUrl':
				$this->seoUrl($id);
				break;
			case 'checkout/order/addHistory':
				// The storefront's stock changes with an order's status, in SQL.
				$this->changes->products(array_map(static fn (array $row) => (int) $row['product_id'], $this->db->rows(
					'SELECT DISTINCT `product_id` FROM ' . $this->db->table('order_product') . ' WHERE `order_id` = ' . $id,
				)));
				break;
		}
	}

	/**
	 * An SEO URL is an item's link: that item syncs.
	 */
	private function seoUrl(int $id): void {
		$row = $this->db->row('SELECT `key`, `value` FROM ' . $this->db->table('seo_url') . ' WHERE `seo_url_id` = ' . $id);
		if ($row === null) {
			return;
		}
		$value = (string) $row['value'];
		match ((string) $row['key']) {
			'product_id' => $this->changes->products([(int) $value]),
			// A category's path ("20_27"): the category is the last.
			'path' => $this->changes->add('category', [(int) substr((string) strrchr('_' . $value, '_'), 1)]),
			'manufacturer_id' => $this->changes->add('manufacturer', [(int) $value]),
			'information_id' => $this->changes->add('information', [(int) $value]),
			default => null,
		};
	}
}
