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

use Bitlane\OpenCart\Documents\ProductDocuments;
use Bitlane\OpenCart\Support\Database;
use Bitlane\OpenCart\Support\Translator;

/**
 * The sources of documents by id: products, categories, manufacturers and
 * information pages (docs/opencart-plan.md OC5).
 */
final class Sources {
	/** @var array<string, Source>|null */
	private ?array $sources = null;

	public function __construct(private Database $db, private Translator $translator) {
	}

	/**
	 * @return array<string, Source>
	 */
	public function all(): array {
		if ($this->sources === null) {
			$this->sources = [];
			foreach ([
				new ProductSource($this->db, $this->translator, new ProductDocuments($this->db)),
				new CategorySource($this->db, $this->translator),
				new ManufacturerSource($this->db, $this->translator),
				new InformationSource($this->db, $this->translator),
			] as $source) {
				$this->sources[$source->id()] = $source;
			}
		}

		return $this->sources;
	}

	public function get(string $id): ?Source {
		return $this->all()[$id] ?? null;
	}
}
