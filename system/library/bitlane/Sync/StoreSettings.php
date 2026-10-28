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

use Bitlane\OpenCart\Site\Settings;
use Bitlane\OpenCart\Site\Stores;
use Bitlane\OpenCart\Support\State;

/**
 * Store settings that change every document, which no event announces
 * (docs/opencart-plan.md OC10): prices and stock as guests see them change
 * every product's (tax display, the store's address for tax, its customer
 * group, currency, hidden prices, backorders, image sizes), links every
 * source's (SEO URLs on or off, the store's address). Their hash is compared
 * hourly and by the daily check; a change starts the full syncs it needs.
 */
final class StoreSettings {
	private const NAME = 'store_settings';

	/** What every product's documents depend on. */
	private const PRODUCTS = [
		'config_tax', 'config_tax_default', 'config_country_id', 'config_zone_id', 'config_customer_group_id',
		'config_customer_price', 'config_currency', 'config_stock_checkout',
		'config_image_product_width', 'config_image_product_height',
	];

	/** What every document's link and image depend on. */
	private const LINKS = ['config_seo_url', 'config_image_category_width', 'config_image_category_height'];

	public function __construct(
		private State $state,
		private Settings $settings,
		private Stores $stores,
		private FullSync $full,
	) {
	}

	/**
	 * Starts the full syncs the store's changed settings need; at most hourly
	 * unless $now.
	 */
	public function check(int $storeId, bool $now = true): void {
		$kept = $this->state->json(self::NAME, $storeId);
		if (!$now && (int) ($kept['at'] ?? 0) > time() - 3600) {
			return;
		}
		$hashes = $this->hashes($storeId);
		$this->state->setJson(self::NAME, $hashes + ['at' => time()], $storeId);
		if (!isset($kept['products'])) {
			return;
		}
		$sources = $this->settings->get($storeId)['sources'];
		if ($kept['links'] !== $hashes['links']) {
			array_map(fn ($source) => $this->full->start($storeId, $source), $sources);
		} elseif ($kept['products'] !== $hashes['products'] && in_array('product', $sources, true)) {
			$this->full->start($storeId, 'product');
		}
	}

	/**
	 * Notes the settings as synced: the store's documents were just made for them.
	 */
	public function remember(int $storeId): void {
		$this->state->setJson(self::NAME, $this->hashes($storeId) + ['at' => time()], $storeId);
	}

	/**
	 * @return array{products: string, links: string}
	 */
	private function hashes(int $storeId): array {
		$values = fn (array $keys) => array_map(fn (string $key) => $this->stores->setting($storeId, $key), $keys);

		return [
			'products' => md5((string) json_encode($values(self::PRODUCTS))),
			'links' => md5((string) json_encode(array_merge($values(self::LINKS), [$this->stores->url($storeId)]))),
		];
	}
}
