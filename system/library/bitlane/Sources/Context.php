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

use Bitlane\OpenCart\Support\Translator;
use Opencart\System\Engine\Registry;

/**
 * What documents are built for: a store, one of the install's languages (its
 * id, its code as the setup sent it, "en-GB", and OpenCart's, "en-gb"), the
 * filters the merchant picked for the source, how products become
 * documents, and the storefront as a guest sees it in that store and
 * language (Catalog\StoreRegistry), whose models, links and images documents
 * use.
 */
final class Context {
	/**
	 * @param list<string> $facets the picked filters (attributes) this source has
	 * @param string $productDocuments Settings::PER_VARIATION or PER_PRODUCT
	 * @param Translator $texts the extension's texts in the documents' language
	 */
	public function __construct(
		public readonly int $storeId,
		public readonly int $languageId,
		public readonly string $language,
		public readonly string $opencartLanguage,
		public readonly array $facets,
		public readonly string $productDocuments,
		public readonly Registry $catalog,
		public readonly Translator $texts,
	) {
	}

	/**
	 * A catalog model ("catalog/product"), loaded once.
	 */
	public function model(string $route): object {
		$key = 'model_' . str_replace('/', '_', $route);
		if (!$this->catalog->has($key)) {
			$this->catalog->get('load')->model($route);
		}

		return $this->catalog->get($key);
	}

	public function config(string $key): mixed {
		return $this->catalog->get('config')->get($key);
	}

	/**
	 * A storefront link in the context's language ("product/product", "product_id=42").
	 */
	public function link(string $route, string $args): string {
		return html_entity_decode((string) $this->catalog->get('url')->link($route, 'language=' . $this->opencartLanguage . '&' . $args), ENT_QUOTES, 'UTF-8');
	}

	/**
	 * An image of DIR_IMAGE resized as the storefront resizes it; null when
	 * there's none.
	 *
	 * @return array{src: string, srcset?: string, sizes?: string, width: int, height: int, alt?: string}|null
	 */
	public function image(string $file, string $setting, string $alt): ?array {
		$width = (int) $this->config('config_image_' . $setting . '_width');
		$height = (int) $this->config('config_image_' . $setting . '_height');
		if ($file === '' || $width <= 0 || $height <= 0) {
			return null;
		}
		$images = $this->model('tool/image');
		$src = (string) $images->resize($file, $width, $height);
		if ($src === '') {
			return null;
		}
		$image = ['src' => html_entity_decode($src, ENT_QUOTES, 'UTF-8'), 'width' => $width, 'height' => $height];
		// Twice as sharp for high-density screens, in the same proportions.
		$double = (string) $images->resize($file, $width * 2, $height * 2);
		if ($double !== '') {
			$image['srcset'] = $image['src'] . ' ' . $width . 'w, ' . html_entity_decode($double, ENT_QUOTES, 'UTF-8') . ' ' . ($width * 2) . 'w';
			$image['sizes'] = $width . 'px';
		}
		if (trim($alt) !== '') {
			$image['alt'] = trim($alt);
		}

		return $image;
	}
}
