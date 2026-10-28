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

namespace Opencart\Catalog\Controller\Extension\Bitlane;

use Bitlane\OpenCart\Plugin;
use Bitlane\OpenCart\Support\BitlaneError;
use Bitlane\OpenCart\Support\Database;
use Bitlane\OpenCart\Support\Translator;

/**
 * Bitlane's redirect after Connect (docs/opencart-plan.md OC2), on the
 * storefront (index.php?route=extension/bitlane/oauth.callback) because the
 * admin's SameSite=Strict cookie never comes along on a redirect from
 * bitlane.co. It runs in the popup the screen opened: it exchanges the code,
 * connects the store when the grant names its project, leaves the outcome
 * for the admin who clicked Connect (the screen picks it up), and closes the
 * popup.
 *
 * The registry's parts this controller uses (OpenCart's __get):
 * @property \Opencart\System\Library\DB $db
 * @property \Opencart\System\Library\Request $request
 * @property \Opencart\System\Library\Response $response
 */
class Oauth extends \Opencart\System\Engine\Controller {
	public function callback(): void {
		require_once dirname(__DIR__, 2) . '/system/library/bitlane/autoload.php';
		$query = [];
		foreach (['code', 'state', 'iss', 'error', 'error_description'] as $key) {
			if (isset($this->request->get[$key]) && is_string($this->request->get[$key]) && $this->request->get[$key] !== '') {
				$query[$key] = $this->request->get[$key];
			}
		}

		$db = new Database($this->db, DB_PREFIX);
		$plugin = new Plugin($db, Translator::forLanguage('en-gb'), HTTP_SERVER);
		$pending = $plugin->oauth()->pending($query['state'] ?? '');
		// The answer is in the language of the admin who clicked Connect.
		$language = is_string($pending['language'] ?? null) && preg_match('/^[a-z]{2}(-[a-z]{2})?$/', $pending['language']) ? $pending['language'] : 'en-gb';
		$plugin = new Plugin($db, Translator::forLanguage($language), HTTP_SERVER);
		$texts = $plugin->translator();

		$done = false;
		try {
			$grant = $plugin->oauth()->handleCallback($query);
			if (!empty($plugin->connection()->get()['project_id'])) {
				$plugin->actions()->connectStore($grant['store']);
			}
			$message = $texts->t('Connected to Bitlane.');
			$plugin->actions()->setNotice($grant['user'], ['type' => 'success', 'message' => $message]);
			$done = true;
		} catch (BitlaneError $e) {
			$message = $e->getMessage();
			if (isset($pending['user'])) {
				$plugin->actions()->setNotice((int) $pending['user'], ['type' => 'error', 'message' => $message]);
			}
		}

		$this->response->addHeader('Content-Type: text/html; charset=utf-8');
		$this->response->addHeader('Cache-Control: no-store');
		$this->response->addHeader('Referrer-Policy: no-referrer');
		$this->response->setOutput($this->page($message, $done ? $texts->t('You can close this window.') : $texts->t('Close this window and click Connect again.'), $done));
	}

	/**
	 * A plain page for the popup; on success it closes itself.
	 */
	private function page(string $message, string $hint, bool $close): string {
		$e = static fn (string $text) => htmlspecialchars($text, ENT_QUOTES, 'UTF-8');

		return '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Bitlane</title>'
			. '<style>body{font:16px/1.5 system-ui,sans-serif;margin:0;display:grid;place-items:center;min-height:100vh;color:#111;text-align:center}p{margin:.25rem 1rem}.hint{color:#666;font-size:14px}</style></head>'
			. '<body><main><p><strong>' . $e($message) . '</strong></p><p class="hint">' . $e($hint) . '</p></main>'
			. ($close ? '<script>setTimeout(function () { window.close(); }, 600);</script>' : '')
			. '</body></html>';
	}
}
