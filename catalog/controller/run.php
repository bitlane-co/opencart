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
use Bitlane\OpenCart\Support\Database;
use Bitlane\OpenCart\Support\Translator;

/**
 * Runs the extension's due jobs (docs/opencart-plan.md OC11):
 * index.php?route=extension/bitlane/run&key=…, which a cron of the host
 * calls every minute (the line the Jobs tab shows), and run.cron, the entry
 * in OpenCart's own cron table (Extensions › Cron Jobs), run hourly by
 * OpenCart's cron when the host calls it. Like every storefront route, it
 * answers only the maintenance page while the store is in maintenance mode.
 *
 * The registry's parts this controller uses (OpenCart's __get):
 * @property \Opencart\System\Library\DB $db
 * @property \Opencart\System\Library\Log $log
 * @property \Opencart\System\Library\Request $request
 * @property \Opencart\System\Library\Response $response
 */
class Run extends \Opencart\System\Engine\Controller {
	public function index(): void {
		$plugin = $this->plugin();
		$runner = $plugin->runner();
		if (!hash_equals($runner->secret(), (string) ($this->request->get['key'] ?? ''))) {
			http_response_code(403);
			$this->response->setOutput('');

			return;
		}
		// The caller may stop waiting: the run goes on.
		ignore_user_abort(true);
		// Hosts that disable it remove it (PHP 8).
		if (function_exists('set_time_limit')) {
			set_time_limit(90);
		}
		$ran = $runner->run(50);
		$this->response->addHeader('Content-Type: application/json');
		$this->response->addHeader('Cache-Control: no-store');
		$this->response->setOutput((string) json_encode(['ran' => $ran]));
	}

	/**
	 * OpenCart's cron entry (its arguments, the entry's id, code, cycle and
	 * dates, aren't needed).
	 */
	public function cron(): void {
		$this->plugin()->runner()->run(20);
	}

	private function plugin(): Plugin {
		require_once dirname(__DIR__, 2) . '/system/library/bitlane/autoload.php';
		$log = $this->log;

		return new Plugin(new Database($this->db, DB_PREFIX), Translator::forLanguage('en-gb'), HTTP_SERVER, null, static function (string $message) use ($log): void {
			$log->write($message);
		}, $this->registry);
	}
}
