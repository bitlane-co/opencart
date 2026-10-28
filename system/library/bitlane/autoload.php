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

// Our own autoloader for Bitlane\OpenCart\ (docs/opencart-plan.md OC19):
// OpenCart's changed as late as 4.1.0.4, and only knows its own namespaces.
spl_autoload_register(static function (string $class): void {
	if (str_starts_with($class, 'Bitlane\\OpenCart\\')) {
		$file = __DIR__ . '/' . str_replace('\\', '/', substr($class, strlen('Bitlane\\OpenCart\\'))) . '.php';
		if (is_file($file)) {
			require $file;
		}
	}
});
