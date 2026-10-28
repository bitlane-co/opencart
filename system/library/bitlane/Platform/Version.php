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

namespace Bitlane\OpenCart\Platform;

/**
 * What differs between the OpenCart versions the extension supports
 * (docs/opencart-plan.md OC1): 4.0.2.3 and 4.1.0.x. One place for every
 * difference, read from OpenCart's VERSION.
 */
final class Version {
	public const OLDEST = '4.0.2.3';

	/** The first version not yet checked. */
	public const NEWER = '4.2';

	public function __construct(private string $version) {
	}

	public static function current(): self {
		return new self(defined('VERSION') ? (string) constant('VERSION') : '0');
	}

	public function name(): string {
		return $this->version;
	}

	public function supported(): bool {
		return version_compare($this->version, self::OLDEST, '>=') && version_compare($this->version, self::NEWER, '<');
	}

	/**
	 * 4.1 or newer: model event triggers carry a dot before the method
	 * (`admin/model/catalog/product.editProduct/after`), specials are in
	 * product_discount.
	 */
	public function atLeast41(): bool {
		return version_compare($this->version, '4.1', '>=');
	}

	/**
	 * The events the extension needs on this version, by code: what triggers
	 * them and our controller method that handles them.
	 *
	 * @return array<string, array{trigger: string, action: string, description: string}>
	 */
	public function events(): array {
		$events = [];
		// The catalogue's models whose changes the search follows (Sync\Events).
		$admin = [
			'catalog/product' => ['addProduct', 'editProduct', 'copyProduct', 'addVariant', 'editVariant', 'editVariants', 'editRating', 'editMasterId'],
			'catalog/category' => ['addCategory', 'editCategory', 'deleteCategory'],
			'catalog/manufacturer' => ['addManufacturer', 'editManufacturer', 'deleteManufacturer'],
			'catalog/information' => ['addInformation', 'editInformation', 'deleteInformation'],
			'catalog/review' => ['addReview', 'editReview'],
			'catalog/attribute' => ['editAttribute', 'deleteAttribute'],
			'catalog/option' => ['editOption', 'deleteOption'],
			'catalog/filter' => ['editFilter', 'deleteFilter'],
			'design/seo_url' => ['addSeoUrl', 'editSeoUrl'],
		];
		if ($this->atLeast41()) {
			$admin['catalog/filter_group'] = ['editFilterGroup', 'deleteFilterGroup'];
		}
		foreach ($admin as $model => $methods) {
			foreach ($methods as $method) {
				$events['bitlane_' . $this->code($model, $method)] = $this->modelEvent('admin', $model, $method, 'after', 'extension/bitlane/other/bitlane.changed');
			}
		}
		// What a deletion takes away is read before it.
		foreach (['catalog/product' => 'deleteProduct', 'catalog/review' => 'deleteReview', 'design/seo_url' => 'deleteSeoUrl'] as $model => $method) {
			$events['bitlane_' . $this->code($model, $method)] = $this->modelEvent('admin', $model, $method, 'before', 'extension/bitlane/other/bitlane.changing');
		}
		// Orders change stock in SQL, as their status changes; the status is
		// also a purchase or a refund (Storefront\ShopEvents).
		$events['bitlane_order_history'] = $this->modelEvent('catalog', 'checkout/order', 'addHistory', 'after', 'extension/bitlane/event.changed');
		// The storefront: the widget's snippet, and the widget's session kept
		// for the cart and the order.
		foreach (['addOrder', 'editOrder'] as $method) {
			$events['bitlane_' . $this->code('checkout/order', $method)] = $this->modelEvent('catalog', 'checkout/order', $method, 'after', 'extension/bitlane/event.order');
		}

		return $events + [
			'bitlane_storefront' => [
				'trigger' => 'catalog/view/common/header/after',
				'action' => 'extension/bitlane/event.header',
				'description' => 'Bitlane: the search widget on the store’s pages',
			],
			'bitlane_cart_add' => [
				// Controller methods' triggers have their dot in 4.0 too.
				'trigger' => 'catalog/controller/checkout/cart.add/after',
				'action' => 'extension/bitlane/event.cart',
				'description' => 'Bitlane: additions to the cart, for search analytics',
			],
			'bitlane_menu' => [
				'trigger' => 'admin/view/common/column_left/before',
				'action' => 'extension/bitlane/other/bitlane.menu',
				'description' => 'Bitlane: its item in the admin menu',
			],
			'bitlane_menu_icon' => [
				'trigger' => 'admin/view/common/header/before',
				'action' => 'extension/bitlane/other/bitlane.menuIcon',
				'description' => 'Bitlane: the stylesheet that draws its menu item’s icon',
			],
		];
	}

	/**
	 * A model method's event: "admin/model/catalog/product/editProduct/after"
	 * in 4.0, "admin/model/catalog/product.editProduct/after" in 4.1.
	 *
	 * @return array{trigger: string, action: string, description: string}
	 */
	private function modelEvent(string $application, string $model, string $method, string $when, string $action): array {
		return [
			'trigger' => $application . '/model/' . $model . ($this->atLeast41() ? '.' : '/') . $method . '/' . $when,
			'action' => $action,
			'description' => 'Bitlane: keeps the search in sync (' . $model . ' ' . $method . ')',
		];
	}

	/**
	 * An event's code from its model and method ("catalog_product_editproduct"), at most 56 characters.
	 */
	private function code(string $model, string $method): string {
		return substr(strtolower(str_replace('/', '_', $model) . '_' . $method), 0, 56);
	}
}
