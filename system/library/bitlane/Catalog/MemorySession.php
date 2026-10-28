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

namespace Bitlane\OpenCart\Catalog;

/**
 * A guest's session that's never stored: what the catalog's startups read
 * and write while documents are built. OpenCart's own writes itself at
 * shutdown once constructed, and fails if it never started.
 */
final class MemorySession extends \Opencart\System\Library\Session {
	/**
	 * @param string $id what getId() answers (a shopper's, in the tests)
	 */
	public function __construct(private string $id = '') {
	}

	public function getId(): string {
		return $this->id;
	}

	public function start(string $session_id = ''): string {
		return '';
	}

	public function close(): void {
	}

	public function destroy(): void {
		$this->data = [];
	}

	public function gc(): void {
	}
}
