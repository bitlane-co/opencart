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

namespace Bitlane\OpenCart\Support;

/**
 * The extension's texts: written in English, which is also each one's key in
 * the language files (admin/language/<code>/other/bitlane.php; it-it holds
 * the Italian), so a language without a file shows English. %name% in a text
 * is replaced by $params['name'].
 */
final class Translator {
	/**
	 * @param array<string, string> $texts the language file's, text => translation
	 */
	public function __construct(private array $texts, private string $code) {
	}

	/**
	 * Reads the language file for an OpenCart language code (en-gb, it-it).
	 */
	public static function forLanguage(string $code): self {
		$_ = [];
		$file = dirname(__DIR__, 4) . '/admin/language/' . basename($code) . '/other/bitlane.php';
		if (is_file($file)) {
			require $file;
		}

		return new self(array_filter($_, 'is_string'), $code);
	}

	/**
	 * @param array<string, string|int> $params
	 */
	public function t(string $text, array $params = []): string {
		$translated = $this->texts[$text] ?? $text;

		return (string) preg_replace_callback('/%(\w+)%/', static fn (array $m) => array_key_exists($m[1], $params) ? (string) $params[$m[1]] : $m[0], $translated);
	}

	/**
	 * The language's code as OpenCart has it (en-gb).
	 */
	public function code(): string {
		return $this->code;
	}

	/**
	 * Every text with its translation, for the screen.
	 *
	 * @return array<string, string>
	 */
	public function all(): array {
		return $this->texts;
	}
}
