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

namespace Opencart\Admin\Controller\Extension\Bitlane\Other;

use Bitlane\OpenCart\Plugin;
use Bitlane\OpenCart\Support\BitlaneError;
use Bitlane\OpenCart\Support\Database;
use Bitlane\OpenCart\Support\Translator;

/**
 * The Bitlane screen (docs/opencart-plan.md OC14), an extension of type
 * "other": the page with the screen's React app (built into
 * admin/view/javascript/bitlane), its JSON actions (`.action&action=…`), the
 * install and uninstall OpenCart calls, and the admin menu's item (an event).
 * OpenCart checks the route's access permission and the user_token before
 * any of it runs; changes also need the modify permission.
 *
 * PHP 8.0 syntax only in this file: OpenCart 4.0.2.3 runs on PHP 8.0 and
 * loads it to list the extension, while the library needs 8.1, which the
 * page checks first.
 *
 * The registry's parts this controller uses (OpenCart's __get):
 * @property \Opencart\System\Engine\Config $config
 * @property \Opencart\System\Engine\Loader $load
 * @property \Opencart\System\Library\Cart\User $user
 * @property \Opencart\System\Library\DB $db
 * @property \Opencart\System\Library\Document $document
 * @property \Opencart\System\Library\Log $log
 * @property \Opencart\System\Library\Request $request
 * @property \Opencart\System\Library\Response $response
 * @property \Opencart\System\Library\Session $session
 * @property \Opencart\System\Library\Url $url
 * @property \Opencart\Admin\Model\Setting\Setting $model_setting_setting
 */
class Bitlane extends \Opencart\System\Engine\Controller {
	private const ROUTE = 'extension/bitlane/other/bitlane';

	/** @var Plugin|null */
	private $plugin = null;

	public function index(): void {
		$texts = $this->texts();
		$this->document->setTitle('Bitlane');

		$problem = $this->problem();
		if ($problem === null) {
			// Events changed by an upgrade or another extension come back.
			$this->plugin()->installer()->install();
			$version = Plugin::extensionVersion();
			$asset = HTTP_CATALOG . 'extension/bitlane/admin/view/javascript/bitlane/';
			$built = DIR_EXTENSION . 'bitlane/admin/view/javascript/bitlane/';
			if (is_file($built . 'admin.js')) {
				$this->document->addStyle($asset . 'admin.css?v=' . filemtime($built . 'admin.css'));
				$this->document->addScript($asset . 'admin.js?v=' . filemtime($built . 'admin.js'));
			}
			$data['config'] = htmlspecialchars((string) json_encode([
				'actions' => $this->url->link(self::ROUTE . '.action', 'user_token=' . $this->session->data['user_token'], true),
				'page' => $this->url->link(self::ROUTE, 'user_token=' . $this->session->data['user_token'], true),
				'store' => $this->storeId(),
				'version' => $version,
				'translations' => $this->plugin()->translator()->all(),
				'logo' => HTTP_CATALOG . 'extension/bitlane/admin/view/image/bitlane/logo.svg',
			]), ENT_QUOTES, 'UTF-8');
		} else {
			$data['problem'] = $problem;
		}

		$data['heading_title'] = 'Bitlane';
		$data['header'] = $this->load->controller('common/header');
		$data['column_left'] = $this->load->controller('common/column_left');
		$data['footer'] = $this->load->controller('common/footer');
		$data['text_problem'] = $texts->t('Bitlane can’t run here');

		$this->response->setOutput($this->load->view('extension/bitlane/other/bitlane', $data));
	}

	/**
	 * Called by Extensions › Others › Install, after OpenCart granted the
	 * installing admin's user group access to the screen.
	 */
	public function install(): void {
		if (!$this->user->hasPermission('modify', 'extension/other') || $this->problem() !== null) {
			return;
		}
		$this->plugin()->installer()->install();
		// Extensions › Others shows the extension as enabled.
		$this->load->model('setting/setting');
		$this->model_setting_setting->editSetting('other_bitlane', ['other_bitlane_status' => 1]);
	}

	/**
	 * Called by Extensions › Others › Uninstall, which is also how OpenCart
	 * updates an extension: the tables and the connection stay (OC18).
	 */
	public function uninstall(): void {
		if (!$this->user->hasPermission('modify', 'extension/other') || $this->problem() !== null) {
			return;
		}
		$this->plugin()->installer()->uninstall();
	}

	/**
	 * The screen's JSON actions: ?action=<name>, GET to read, POST with a
	 * JSON body to change something; &store_id for the store worked on.
	 */
	public function action(): void {
		$action = (string) ($this->request->get['action'] ?? '');
		$this->respond(function () use ($action) {
			$problem = $this->problem();
			if ($problem !== null) {
				throw new BitlaneError('unsupported', $problem, 409);
			}
			$actions = $this->plugin()->actions();
			$userId = (int) $this->user->getId();
			switch ($action) {
				case 'status':
					return $actions->status($this->storeId(), $userId);
				case 'authorize':
					$this->requireModify();

					return ['authorize_url' => $actions->authorize($this->requireStore(), $userId)];
				case 'projects':
					return $actions->projects();
				case 'project':
					$this->requireModify();
					$storeId = $this->requireStore();
					$projectId = (string) ($this->input()['project_id'] ?? '');
					// Bitlane answers a request without a project 401, which reads as a revoked grant.
					if ($projectId === '') {
						throw new BitlaneError('no_project', $this->texts()->t('Choose a project first.'), 400);
					}
					$actions->connectStore($storeId, $projectId);

					return $actions->status($storeId, $userId);
				case 'disconnect':
					$this->requireModify();
					$storeId = $this->requireStore();
					$actions->disconnect($storeId);

					return $actions->status($storeId, $userId);
				case 'engines':
					return $actions->engines($this->requireStore());
				case 'setup':
					$this->requireModify();

					return $actions->setup($this->requireStore(), $userId, $this->input());
				case 'resync':
					$this->requireModify();
					$source = $this->input()['source'] ?? null;

					return $actions->resync($this->requireStore(), $userId, is_string($source) && $source !== '' ? $source : null);
				case 'storefront':
					$this->requireModify();

					return $actions->storefront($this->requireStore(), $userId, $this->input());
				case 'run':
					return $actions->run($this->requireStore(), $userId);
				case 'jobs':
					return $actions->jobs($this->requireStore());
				case 'job_again':
					$this->requireModify();

					return $actions->jobAgain($this->requireStore(), (int) ($this->input()['id'] ?? 0));
				case 'job_delete':
					$this->requireModify();

					return $actions->jobDelete($this->requireStore(), (int) ($this->input()['id'] ?? 0));
			}
			throw new BitlaneError('not_found', 'Unknown action.', 404);
		});
	}

	/**
	 * Event admin/view/common/column_left/before: Bitlane as a top-level item
	 * of the admin menu, after Catalog, for those who may open it.
	 *
	 * @param array<string, mixed> $data
	 */
	public function menu(string &$route, array &$data): void {
		if (!$this->user->hasPermission('access', self::ROUTE) || !isset($data['menus']) || !is_array($data['menus'])) {
			return;
		}
		$item = [
			'id' => 'menu-bitlane',
			// The Bitlane mark, drawn by the stylesheet menuIcon() adds.
			'icon' => 'bitlane-menu-icon',
			'name' => 'Bitlane',
			'href' => $this->url->link(self::ROUTE, 'user_token=' . $this->session->data['user_token']),
			'children' => [],
		];
		$after = array_search('menu-catalog', array_column($data['menus'], 'id'), true);
		array_splice($data['menus'], $after === false ? count($data['menus']) : $after + 1, 0, [$item]);
	}

	/**
	 * Event admin/view/common/header/before: the stylesheet that draws the
	 * menu item's icon, on every admin page (the header is rendered before
	 * the menu, so menu() can't add it).
	 *
	 * @param array<string, mixed> $data
	 */
	public function menuIcon(string &$route, array &$data): void {
		if (!$this->user->hasPermission('access', self::ROUTE)) {
			return;
		}
		$file = DIR_EXTENSION . 'bitlane/admin/view/stylesheet/bitlane/menu.css';
		if (is_file($file)) {
			$href = HTTP_CATALOG . 'extension/bitlane/admin/view/stylesheet/bitlane/menu.css?v=' . filemtime($file);
			$data['styles'][$href] = ['href' => $href, 'rel' => 'stylesheet', 'media' => 'screen'];
		}
	}

	/**
	 * Events after the catalogue's models change something (Sync\Events):
	 * what changed is queued for the search. Never in the way of the save:
	 * a failure is only logged.
	 *
	 * @param array<mixed> $args
	 */
	public function changed(string &$route, array &$args, mixed &$output = null): void {
		$this->onModel($route, $args, $output, false);
	}

	/**
	 * Events before a deletion: what it takes away is read while it's there.
	 *
	 * @param array<mixed> $args
	 */
	public function changing(string &$route, array &$args): void {
		$this->onModel($route, $args, null, true);
	}

	/**
	 * @param array<mixed> $args
	 */
	private function onModel(string $route, array $args, mixed $output, bool $before): void {
		if ($this->problem() !== null) {
			return;
		}
		try {
			$events = $this->plugin()->events();
			// 4.1 names a model's method after a dot (catalog/product.editProduct).
			$route = str_replace('.', '/', $route);
			$before ? $events->before($route, $args) : $events->after($route, $args, $output);
		} catch (\Throwable $e) {
			$this->log->write('Bitlane: ' . $route . ': ' . $e->getMessage() . ' at ' . $e->getFile() . ':' . $e->getLine());
		}
	}

	/**
	 * Why the extension can't run in this install, in the admin's language;
	 * null when it can.
	 */
	private function problem(): ?string {
		$texts = $this->texts();
		if (version_compare(PHP_VERSION, '8.1', '<')) {
			return $texts->t('Bitlane needs PHP 8.1 or newer; this server runs %version%.', ['version' => PHP_VERSION]);
		}
		if (!function_exists('sodium_crypto_secretbox')) {
			return $texts->t('Bitlane needs PHP’s sodium extension, which keeps the connection’s keys encrypted.');
		}
		if (!$this->plugin()->version()->supported()) {
			return $texts->t('Bitlane works with OpenCart %oldest% to 4.1; this store runs %version%.', ['oldest' => '4.0.2.3', 'version' => VERSION]);
		}

		return null;
	}

	/**
	 * Answers an action as JSON: its result, or {code, message, status,
	 * errors} with the error's status (403 without the permission).
	 */
	private function respond(callable $action): void {
		try {
			$body = $action();
		} catch (BitlaneError $e) {
			http_response_code($e->status && $e->status >= 400 && $e->status < 500 ? $e->status : 502);
			$body = $e->toArray();
		} catch (\Throwable $e) {
			$this->log->write('Bitlane: ' . $e->getMessage() . ' at ' . $e->getFile() . ':' . $e->getLine());
			http_response_code(500);
			$body = (new BitlaneError('unexpected', $this->texts()->t('Something went wrong. The details are in System › Maintenance › Error Logs.')))->toArray();
		}
		$this->response->addHeader('Content-Type: application/json; charset=utf-8');
		$this->response->addHeader('Cache-Control: no-store');
		$this->response->setOutput((string) json_encode($body));
	}

	private function requireModify(): void {
		if (!$this->user->hasPermission('modify', self::ROUTE)) {
			throw new BitlaneError('forbidden', $this->texts()->t('You do not have permission to do this.'), 403);
		}
	}

	/**
	 * The store worked on: &store_id, else the only store; null for an
	 * install with several stores, none picked yet.
	 */
	private function storeId(): ?int {
		if (isset($this->request->get['store_id']) && is_numeric($this->request->get['store_id'])) {
			return (int) $this->request->get['store_id'];
		}

		return count($this->plugin()->stores()->all()) === 1 ? 0 : null;
	}

	private function requireStore(): int {
		$storeId = $this->storeId();
		if ($storeId === null) {
			throw new BitlaneError('no_store', $this->texts()->t('Choose a store first.'), 400);
		}

		return $storeId;
	}

	/**
	 * The action's JSON body.
	 *
	 * @return array<string, mixed>
	 */
	private function input(): array {
		$data = json_decode((string) file_get_contents('php://input'), true);

		return is_array($data) ? $data : [];
	}

	private function plugin(): Plugin {
		if ($this->plugin === null) {
			$log = $this->log;
			$this->plugin = new Plugin(new Database($this->db, DB_PREFIX), $this->texts(), HTTP_CATALOG, null, static function (string $message) use ($log): void {
				$log->write($message);
			}, $this->registry);
		}

		return $this->plugin;
	}

	/**
	 * The texts in the admin's language: the language cookie, else the
	 * store's admin language (as OpenCart's startup/language picks it).
	 */
	private function texts(): Translator {
		require_once dirname(__DIR__, 3) . '/system/library/bitlane/autoload.php';
		$code = (string) ($this->request->cookie['language'] ?? $this->config->get('config_language_admin'));

		return Translator::forLanguage(preg_match('/^[a-z]{2}(-[a-z]{2})?$/', $code) ? $code : 'en-gb');
	}
}
