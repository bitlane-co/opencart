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

namespace Bitlane\OpenCart\Site;

use Bitlane\OpenCart\Sources\Sources;
use Bitlane\OpenCart\Support\Database;
use Bitlane\OpenCart\Support\Translator;

/**
 * A store as the setup screen offers it (docs/opencart-plan.md OC5, OC12,
 * OC13): the content it can search with their counts, the filters each has,
 * the sort choices, the currency's format, the logo and the default setup.
 */
final class Site {
	public function __construct(
		private Database $db,
		private Stores $stores,
		private Sources $sources,
		private Translator $translator,
	) {
	}

	/**
	 * The content that can be searched, products first, with how many a
	 * guest can find.
	 *
	 * @return list<array{id: string, label: string, kind: string, count: int}>
	 */
	public function sources(int $storeId): array {
		return array_values(array_map(static fn ($source) => [
			'id' => $source->id(),
			'label' => $source->label(),
			'kind' => $source->kind(),
			'count' => $source->count($storeId),
		], $this->sources->all()));
	}

	/**
	 * The filters the given content can have, each with the sources that
	 * have it, named in a language (the admin's without one), in the
	 * sources' order.
	 *
	 * @param string[] $sourceIds
	 *
	 * @return list<array{attribute: string, label: string, sources: list<string>}>
	 */
	public function facets(array $sourceIds, ?int $languageId = null): array {
		$texts = $this->translator;
		if ($languageId === null) {
			$languageId = $this->languageId($this->translator->code());
		} else {
			foreach ($this->stores->languages() as $language) {
				if ($language['id'] === $languageId) {
					$texts = Translator::forLanguage($language['opencart']);
				}
			}
		}

		$facets = [];
		foreach ($this->sources->all() as $id => $source) {
			if (!in_array($id, $sourceIds, true)) {
				continue;
			}
			foreach ($source->facets($languageId, $texts) as $attribute => $label) {
				$facets[$attribute] ??= ['attribute' => (string) $attribute, 'label' => $label, 'sources' => []];
				$facets[$attribute]['sources'][] = (string) $id;
			}
		}

		return array_values($facets);
	}

	/**
	 * The widget's sort choices a source can have, by its kind, and those it
	 * starts with. The ids are Bitlane's (server/integration/presets.go),
	 * which names them in the widget.
	 *
	 * @return array<string, array{choices: list<array{id: string, label: string}>, defaults: list<string>}>
	 */
	public function sorting(): array {
		$t = $this->translator;
		$both = [
			['id' => 'relevance', 'label' => $t->t('Relevance')],
			['id' => 'newest', 'label' => $t->t('Newest')],
			['id' => 'oldest', 'label' => $t->t('Oldest')],
			['id' => 'title_asc', 'label' => $t->t('Name: A to Z')],
		];

		return [
			'products' => [
				'choices' => array_merge($both, [
					['id' => 'price_asc', 'label' => $t->t('Price: low to high')],
					['id' => 'price_desc', 'label' => $t->t('Price: high to low')],
					['id' => 'best_selling', 'label' => $t->t('Best selling')],
					['id' => 'top_rated', 'label' => $t->t('Top rated')],
				]),
				'defaults' => ['relevance', 'price_asc', 'price_desc', 'newest', 'best_selling'],
			],
			'other' => ['choices' => $both, 'defaults' => ['relevance', 'newest']],
		];
	}

	/**
	 * How the store shows prices in a language, which the widget follows: the
	 * currency's symbol and decimals (Localisation › Currencies), the
	 * language's separators (its catalog language file).
	 *
	 * @param string $language OpenCart's code (en-gb)
	 *
	 * @return array{position: string, thousand_sep: string, decimal_sep: string, decimals: int}|null
	 */
	public function currencyFormat(int $storeId, string $language): ?array {
		$currency = $this->db->row('SELECT `symbol_left`, `symbol_right`, `decimal_place` FROM ' . $this->db->table('currency')
			. ' WHERE `code` = ' . $this->db->quote($this->stores->currency($storeId)));
		if ($currency === null) {
			return null;
		}
		$texts = self::languageFile(DIR_OPENCART . 'catalog/language/' . basename($language) . '/default.php');
		$left = (string) $currency['symbol_left'];
		$symbol = $left !== '' ? $left : (string) $currency['symbol_right'];
		$spaced = $symbol !== trim($symbol);

		return [
			'position' => ($left !== '' ? 'left' : 'right') . ($spaced ? '_space' : ''),
			'thousand_sep' => (string) ($texts['thousand_point'] ?? ','),
			'decimal_sep' => (string) ($texts['decimal_point'] ?? '.'),
			'decimals' => max(0, min(4, (int) $currency['decimal_place'])),
		];
	}

	/**
	 * The store's logo (System › Settings › Image), for the widget; empty
	 * without one.
	 */
	public function logoUrl(int $storeId): string {
		$logo = (string) $this->db->value('SELECT `value` FROM ' . $this->db->table('setting')
			. ' WHERE `store_id` IN (0, ' . $storeId . ") AND `code` = 'config' AND `key` = 'config_logo' ORDER BY `store_id` DESC LIMIT 1");

		return $logo !== '' && is_file(DIR_IMAGE . $logo) ? $this->stores->url($storeId) . '/image/' . $logo : '';
	}

	/**
	 * What the setup screen starts with: products and categories, the
	 * filters shoppers use most, the default theme's search box (4.0's and
	 * 4.1's: docs/opencart-plan.md OC15) and the store's logo.
	 *
	 * @return array{sources: list<string>, facets: list<string>, search_selector: string, brand_color: string, logo_url: string}
	 */
	public function defaultSetup(int $storeId): array {
		$sources = ['product', 'category'];
		$facets = array_values(array_filter(
			array_column($this->facets($sources), 'attribute'),
			static fn ($attribute) => in_array($attribute, ['price', 'category', 'manufacturer'], true) || str_starts_with($attribute, 'option_'),
		));

		return [
			'sources' => $sources,
			'facets' => $facets,
			'search_selector' => 'header input[name="search"], #search button, header form[action*="product/search"] button',
			'brand_color' => '',
			'logo_url' => $this->logoUrl($storeId),
		];
	}

	/**
	 * An OpenCart language file's texts ($_ in it); empty without the file.
	 *
	 * @return array<mixed>
	 */
	private static function languageFile(string $file): array {
		$_ = [];
		if (is_file($file)) {
			require $file;
		}

		return $_;
	}

	/**
	 * A language's id by OpenCart's code, else the first enabled one's.
	 */
	private function languageId(string $code): int {
		$languages = $this->stores->languages();
		foreach ($languages as $language) {
			if ($language['opencart'] === $code) {
				return $language['id'];
			}
		}

		return $languages[0]['id'] ?? 1;
	}
}
