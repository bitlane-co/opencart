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

use Opencart\System\Engine\Registry;

/**
 * The storefront as a guest sees it in one store and language, for building
 * documents from the admin, the storefront or a cron alike
 * (docs/opencart-plan.md Part 3): OpenCart's own catalog models, its prices
 * (the store's customer group, tax and currency), SEO links and resized
 * images. Built as OpenCart's createStoreInstance() builds one, with what it
 * lacks: the store's address as the request's (OpenCart picks the store by
 * it; 4.1 ignores the id it's given), and startup/seo_url for the links.
 */
final class StoreRegistry {
	/**
	 * @param Registry $app the running application's (admin or catalog)
	 * @param string $storeUrl the store's address, without a trailing slash
	 * @param string $language OpenCart's code (en-gb)
	 */
	public static function create(Registry $app, int $storeId, string $storeUrl, string $language): Registry {
		$catalog = DIR_OPENCART . 'catalog/';
		$autoloader = $app->get('autoloader');
		$autoloader->register('Opencart\Catalog', $catalog);

		$registry = new Registry();
		$registry->set('autoloader', $autoloader);
		$config = new \Opencart\System\Engine\Config();
		$config->addPath(DIR_CONFIG);
		$config->load('default');
		$config->load('catalog');
		$config->set('application', 'Catalog');
		$config->set('config_store_id', $storeId);
		$registry->set('config', $config);
		$registry->set('log', $app->get('log'));

		$event = new \Opencart\System\Engine\Event($registry);
		$registry->set('event', $event);
		foreach ((array) $config->get('action_event') as $trigger => $actions) {
			foreach ((array) $actions as $priority => $action) {
				$event->register((string) $trigger, new \Opencart\System\Engine\Action((string) $action), (int) $priority);
			}
		}
		// 4.1's loader builds models through it.
		if (class_exists('Opencart\System\Engine\Factory')) {
			$registry->set('factory', new \Opencart\System\Engine\Factory($registry));
		}
		$registry->set('load', new \Opencart\System\Engine\Loader($registry));

		// A request to the store itself: OpenCart's startups read these
		// (4.0.2.3 without checking they're there).
		$parts = parse_url($storeUrl . '/');
		$request = new \stdClass();
		$request->get = ['store_id' => $storeId, 'language' => $language];
		$request->post = [];
		$request->cookie = [];
		$request->files = [];
		$request->server = [
			'HTTPS' => ($parts['scheme'] ?? 'https') === 'https',
			'HTTP_HOST' => ($parts['host'] ?? '') . (isset($parts['port']) ? ':' . $parts['port'] : ''),
			'PHP_SELF' => rtrim((string) ($parts['path'] ?? '/'), '/') . '/index.php',
			'REQUEST_METHOD' => 'GET',
			'REMOTE_ADDR' => '127.0.0.1',
			'HTTP_USER_AGENT' => 'Bitlane',
		];
		$registry->set('request', $request);
		$registry->set('response', new \Opencart\System\Library\Response());
		$registry->set('db', $app->get('db'));
		$registry->set('cache', $app->get('cache'));
		$registry->set('session', new MemorySession());
		// No template engine: documents render nothing.
		$languageLibrary = new \Opencart\System\Library\Language($language);
		$languageLibrary->addPath($catalog . 'language/');
		$languageLibrary->load('default');
		$registry->set('language', $languageLibrary);
		$registry->set('url', new \Opencart\System\Library\Url($storeUrl . '/'));
		$registry->set('document', new \Opencart\System\Library\Document());

		$load = $registry->get('load');
		$load->controller('startup/setting');
		// The store's address, whatever its settings say (and links from it).
		$config->set('config_store_id', $storeId);
		$config->set('config_url', $storeUrl . '/');
		$registry->set('url', new \Opencart\System\Library\Url($storeUrl . '/'));
		// Its default currency, without the startups setting cookies for it
		// or the language (headers may be sent already).
		$registry->get('session')->data['currency'] = (string) $config->get('config_currency');
		$request->cookie = ['currency' => (string) $config->get('config_currency'), 'language' => $language];
		foreach (['startup/seo_url', 'startup/language', 'startup/customer', 'startup/currency', 'startup/tax'] as $startup) {
			$load->controller($startup);
		}

		return $registry;
	}
}
