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
 * The store's enabled categories, as results of their own: "Category", the
 * categories above it, its description and image. 4.1's categories have no
 * dates.
 */
final class CategorySource extends OtherSource {
	public function id(): string {
		return 'category';
	}

	public function label(): string {
		return $this->translator->t('Categories');
	}

	protected function table(): array {
		return ['table' => 'category', 'key' => 'category_id', 'where' => "t.`status` = '1'"];
	}

	protected function document(int $id, Context $context): ?array {
		$row = $this->row($id, $context);
		if ($row === null) {
			return null;
		}
		// The categories above it, the top one first (OpenCart's category_path).
		$path = $this->db->rows('SELECT cp.`path_id`, d.`name` FROM ' . $this->db->table('category_path') . ' cp'
			. ' JOIN ' . $this->db->table('category_description') . ' d ON d.`category_id` = cp.`path_id` AND d.`language_id` = ' . $context->languageId
			. ' WHERE cp.`category_id` = ' . $id . ' ORDER BY cp.`level`');
		$above = array_values(array_filter($path, static fn (array $p) => (int) $p['path_id'] !== $id));
		$name = Fields::plain((string) $row['name']);

		return [
			'title' => $name,
			'permalink' => $context->link('product/category', 'path=' . implode('_', array_column($path, 'path_id'))),
			'type' => $context->texts->t('Category'),
			'subtitle' => implode(' › ', array_map(static fn (array $p) => Fields::plain((string) $p['name']), $above)),
			'short_description' => Fields::text((string) $row['description'], 300),
			'image' => $context->image((string) $row['image'], 'category', $name),
			'content' => Fields::text((string) $row['description']),
			'created_at' => isset($row['date_added']) ? (int) strtotime((string) $row['date_added']) : 0,
			'modified_at' => isset($row['date_modified']) ? (int) strtotime((string) $row['date_modified']) : null,
			'menu_order' => (int) $row['sort_order'],
		];
	}
}
