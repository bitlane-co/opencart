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

use Bitlane\OpenCart\Documents\Fields;

/**
 * The store's manufacturers (brands), each with its page of products: one
 * name for every language, the logo.
 */
final class ManufacturerSource extends OtherSource {
	public function id(): string {
		return 'manufacturer';
	}

	public function label(): string {
		return $this->translator->t('Brands');
	}

	protected function table(): array {
		return ['table' => 'manufacturer', 'key' => 'manufacturer_id', 'where' => '1'];
	}

	protected function document(int $id, Context $context): ?array {
		$row = $this->row($id, $context, false);
		if ($row === null) {
			return null;
		}
		$name = Fields::plain((string) $row['name']);

		return [
			'title' => $name,
			'permalink' => $context->link('product/manufacturer.info', 'manufacturer_id=' . $id),
			'type' => $context->texts->t('Brand'),
			'image' => $context->image((string) $row['image'], 'category', $name),
			'created_at' => 0,
			'menu_order' => (int) $row['sort_order'],
		];
	}
}
