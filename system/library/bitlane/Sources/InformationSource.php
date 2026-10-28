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
 * The store's enabled information pages (About us, Delivery, Privacy…), as
 * "Page" results.
 */
final class InformationSource extends OtherSource {
	public function id(): string {
		return 'information';
	}

	public function label(): string {
		return $this->translator->t('Pages');
	}

	protected function table(): array {
		return ['table' => 'information', 'key' => 'information_id', 'where' => "t.`status` = '1'"];
	}

	protected function document(int $id, Context $context): ?array {
		$row = $this->row($id, $context);
		if ($row === null) {
			return null;
		}

		return [
			'title' => Fields::plain((string) $row['title']),
			'permalink' => $context->link('information/information', 'information_id=' . $id),
			'type' => $context->texts->t('Page'),
			'short_description' => Fields::text((string) ($row['meta_description'] !== '' ? $row['meta_description'] : $row['description']), 300),
			'content' => Fields::text((string) $row['description']),
			'created_at' => 0,
			'menu_order' => (int) $row['sort_order'],
		];
	}
}
