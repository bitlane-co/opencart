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

/**
 * A kind of content whose items become search documents, each source with
 * its own index per language (docs/opencart-plan.md OC5): products,
 * categories, manufacturers and information pages. Every item is in every
 * language, so ids don't depend on it.
 */
interface Source {
	public const PRODUCTS = 'products';
	public const OTHER = 'other';

	/**
	 * Lowercase letters, digits, - and _ ("product"): in index names.
	 */
	public function id(): string;

	/**
	 * Its name on the setup screen ("Brands"), in the admin's language.
	 */
	public function label(): string;

	/**
	 * Source::PRODUCTS (documents of widget_public.ProductDocumentV1) or
	 * Source::OTHER (OtherDocumentV1).
	 */
	public function kind(): string;

	/**
	 * How many items a guest can find in the store (the setup screen, a full
	 * sync's progress).
	 */
	public function count(int $storeId): int;

	/**
	 * The filters its documents can have (each document's
	 * facets.<attribute>), attribute => its name in the language; the
	 * merchant picks some on the setup screen.
	 *
	 * @param Translator $texts the extension's texts in that language
	 *
	 * @return array<string, string>
	 */
	public function facets(int $languageId, Translator $texts): array;

	/**
	 * The store's item ids for the full sync and the daily check, in a stable
	 * order: the next $limit after $after ("" for the first ones).
	 *
	 * @return list<string>
	 */
	public function ids(int $storeId, string $after, int $limit): array;

	/**
	 * Each item's documents in $context's store and language, by item id. An
	 * item that's gone or that a guest can't find has none, and what it had
	 * is deleted. A products source may give several per item (variants,
	 * sharing parent_id); any other gives at most one, whose id is the item's.
	 *
	 * @param list<string> $ids
	 *
	 * @return array<array-key, list<array<string, mixed>>> by item id (PHP makes "42" the key 42)
	 */
	public function documents(array $ids, Context $context): array;

	/**
	 * What the daily check compares with the index, by item: when each last
	 * changed (Unix seconds, as its documents' modified_at says), or, for a
	 * source whose rows have no dates (4.1's categories, every version's
	 * manufacturers and pages), a fingerprint of its rows, kept when it's
	 * synced (Indexer).
	 *
	 * @param list<string> $ids
	 *
	 * @return array{dates: array<array-key, int>}|array{prints: array<array-key, string>}
	 */
	public function changes(int $storeId, array $ids): array;
}
