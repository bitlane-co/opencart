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
use Bitlane\OpenCart\Storefront\Storefront;
use Bitlane\OpenCart\Support\Database;
use Bitlane\OpenCart\Support\Translator;

/**
 * The storefront's events (docs/opencart-plan.md OC9, OC15, OC17): the
 * widget's snippet on every page, the widget's session kept for the cart
 * and the order, and an order's status (its stock for the search, a
 * purchase or a refund for the analytics). Never in the way of a page, a
 * cart or an order: a failure is only logged.
 *
 * The registry's parts this controller uses (OpenCart's __get):
 * @property \Opencart\System\Engine\Config $config
 * @property \Opencart\System\Library\DB $db
 * @property \Opencart\System\Library\Log $log
 * @property \Opencart\System\Library\Request $request
 * @property \Opencart\System\Library\Response $response
 * @property \Opencart\System\Library\Session $session
 */
class Event extends \Opencart\System\Engine\Controller {
	/**
	 * After a catalogue model's method: the order's status (addHistory).
	 *
	 * @param array<mixed> $args
	 */
	public function changed(string &$route, array &$args, mixed &$output = null): void {
		$route = str_replace('.', '/', $route);
		$this->safely($route, function (Plugin $plugin) use ($route, $args, $output): void {
			$plugin->events()->after($route, $args, $output);
		});
		if ($route === 'checkout/order/addHistory') {
			$this->safely($route, static function (Plugin $plugin) use ($args): void {
				$plugin->shopEvents()->statusChanged((int) ($args[0] ?? 0), (int) ($args[1] ?? 0));
			});
		}
	}

	/**
	 * View common/header/after: the snippet before the page's </head>.
	 *
	 * @param array<string, mixed> $data
	 */
	public function header(string &$route, array &$data, mixed &$output): void {
		if (!is_string($output)) {
			return;
		}
		$html = $output;
		$this->safely($route, function (Plugin $plugin) use (&$html): void {
			$waitForConsent = (bool) $this->config->get('config_cookie_id') && (string) ($this->request->cookie['policy'] ?? '') !== '1';
			$html = Storefront::insert($html, $plugin->storefront()->snippet((int) $this->config->get('config_store_id'), $waitForConsent));
		});
		$output = $html;
	}

	/**
	 * Controller checkout/cart.add/after: an addition, when OpenCart took it.
	 *
	 * @param array<mixed> $args
	 */
	public function cart(string &$route, array &$args, mixed &$output = null): void {
		$this->safely($route, function (Plugin $plugin): void {
			$answer = json_decode((string) $this->response->getOutput(), true);
			if (!is_array($answer) || empty($answer['success'])) {
				return;
			}
			$post = $this->request->post;
			$plugin->shopEvents()->added(
				(int) $this->config->get('config_store_id'),
				(int) $this->config->get('config_language_id'),
				$this->session->getId(),
				(string) ($this->request->cookie['bitlane_session'] ?? ''),
				(int) ($post['product_id'] ?? 0),
				isset($post['quantity']) ? (int) $post['quantity'] : 1,
				(string) ($this->request->server['HTTP_USER_AGENT'] ?? ''),
			);
		});
	}

	/**
	 * Model checkout/order addOrder or editOrder, after: the order, in the
	 * shopper's request, gets the widget's session.
	 *
	 * @param array<mixed> $args
	 */
	public function order(string &$route, array &$args, mixed &$output = null): void {
		$this->safely($route, function (Plugin $plugin) use ($route, $args, $output): void {
			$orderId = str_ends_with(str_replace('.', '/', $route), '/addOrder') ? (int) $output : (int) ($args[0] ?? 0);
			$plugin->shopEvents()->orderSaved($orderId, $this->session->getId(), (string) ($this->request->cookie['bitlane_session'] ?? ''));
		});
	}

	/**
	 * Runs $work with the extension, logging what it throws.
	 *
	 * @param callable(Plugin): void $work
	 */
	private function safely(string $route, callable $work): void {
		try {
			require_once dirname(__DIR__, 2) . '/system/library/bitlane/autoload.php';
			$work(new Plugin(new Database($this->db, DB_PREFIX), Translator::forLanguage('en-gb'), HTTP_SERVER));
		} catch (\Throwable $e) {
			$this->log->write('Bitlane: ' . $route . ': ' . $e->getMessage() . ' at ' . $e->getFile() . ':' . $e->getLine());
		}
	}
}
