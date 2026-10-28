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

namespace Bitlane\OpenCart;

use Bitlane\OpenCart\Connection\BitlaneApi;
use Bitlane\OpenCart\Connection\ConnectionStore;
use Bitlane\OpenCart\Connection\OAuthClient;
use Bitlane\OpenCart\Http\Actions;
use Bitlane\OpenCart\Platform\Version;
use Bitlane\OpenCart\Queue\Jobs;
use Bitlane\OpenCart\Queue\Runner;
use Bitlane\OpenCart\Site\Settings;
use Bitlane\OpenCart\Site\Site;
use Bitlane\OpenCart\Site\SiteDetails;
use Bitlane\OpenCart\Site\Stores;
use Bitlane\OpenCart\Sources\Sources;
use Bitlane\OpenCart\Storefront\ShopEvents;
use Bitlane\OpenCart\Storefront\Storefront;
use Bitlane\OpenCart\Support\BitlaneError;
use Bitlane\OpenCart\Support\Crypto;
use Bitlane\OpenCart\Support\Database;
use Bitlane\OpenCart\Support\Http;
use Bitlane\OpenCart\Support\Installer;
use Bitlane\OpenCart\Support\Lock;
use Bitlane\OpenCart\Support\State;
use Bitlane\OpenCart\Support\Translator;
use Bitlane\OpenCart\Support\Updates;
use Bitlane\OpenCart\Sync\Changes;
use Bitlane\OpenCart\Sync\Check;
use Bitlane\OpenCart\Sync\Credentials;
use Bitlane\OpenCart\Sync\Dates;
use Bitlane\OpenCart\Sync\Events;
use Bitlane\OpenCart\Sync\FullSync;
use Bitlane\OpenCart\Sync\Indexer;
use Bitlane\OpenCart\Sync\Meilisearch;
use Bitlane\OpenCart\Sync\StoreSettings;
use Bitlane\OpenCart\Sync\Sync;
use Bitlane\OpenCart\Sync\SyncState;
use Opencart\System\Engine\Registry;

/**
 * The extension's parts, built once per request from OpenCart's database
 * connection: the admin controller, the storefront's controllers and the
 * command line make one each, in the language they work in.
 */
final class Plugin {
	/** The storefront route Bitlane sends the admin back to (OC2). */
	public const CALLBACK_ROUTE = 'extension/bitlane/oauth.callback';

	/** Every Plugin's transport while set: the tests' fake Bitlane, also for the Plugins controllers make. */
	private static ?Http $transport = null;

	private ?State $state = null;
	private ?ConnectionStore $connection = null;
	private ?OAuthClient $oauth = null;
	private ?BitlaneApi $api = null;
	private ?Http $http = null;
	private ?Jobs $jobs = null;
	private ?Runner $runner = null;
	private Version $version;

	/** @var callable(string): void */
	private $log;

	private ?Sources $sources = null;
	private ?Sync $sync = null;
	private ?Changes $changes = null;
	private ?Indexer $indexer = null;

	/**
	 * @param string $catalogUrl store 0's address
	 * @param (callable(string): void)|null $log OpenCart's error log (System › Maintenance › Error Logs)
	 * @param Registry|null $app the running application's registry, from which documents' storefronts are made
	 */
	public function __construct(
		private Database $db,
		private Translator $translator,
		private string $catalogUrl,
		?Version $version = null,
		?callable $log = null,
		private ?Registry $app = null,
	) {
		$this->version = $version ?? Version::current();
		$this->log = $log ?? static function (string $message): void {
			error_log($message);
		};
	}

	/**
	 * The extension's own version, from its install.json.
	 */
	public static function extensionVersion(): string {
		$info = json_decode((string) file_get_contents(dirname(__DIR__, 3) . '/install.json'), true);

		return is_array($info) ? (string) ($info['version'] ?? '') : '';
	}

	public function translator(): Translator {
		return $this->translator;
	}

	public function version(): Version {
		return $this->version;
	}

	public function installer(): Installer {
		return new Installer($this->db, $this->version);
	}

	public function state(): State {
		return $this->state ??= new State($this->db);
	}

	public function updates(): Updates {
		return new Updates($this->state(), $this->http(), self::extensionVersion());
	}

	public function connection(): ConnectionStore {
		return $this->connection ??= new ConnectionStore($this->state(), new Crypto());
	}

	public function oauth(): OAuthClient {
		return $this->oauth ??= new OAuthClient(
			$this->connection(),
			$this->state(),
			$this->http(),
			new Lock($this->db),
			$this->translator,
			self::extensionVersion(),
			rtrim($this->catalogUrl, '/') . '/index.php?route=' . self::CALLBACK_ROUTE,
			rtrim($this->catalogUrl, '/'),
		);
	}

	public function api(): BitlaneApi {
		return $this->api ??= new BitlaneApi($this->oauth(), $this->connection(), $this->http(), $this->translator);
	}

	public function stores(): Stores {
		return new Stores($this->db, $this->catalogUrl);
	}

	public function jobs(): Jobs {
		return $this->jobs ??= new Jobs($this->db);
	}

	/**
	 * The runner, with every job type's handler and the recurring work.
	 */
	public function runner(): Runner {
		if ($this->runner === null) {
			$this->runner = new Runner($this->jobs(), new Lock($this->db), $this->state(), $this->catalogUrl, $this->log);
			$details = $this->siteDetails();
			$this->runner->handle(SiteDetails::JOB, static fn (int $storeId) => $details->send($storeId));
			$this->runner->every('site_details', 3600, static fn () => $details->check());
			$state = $this->syncState();
			// A sync's failure shows on the Overview until one succeeds.
			$sync = static function (callable $work) use ($state): callable {
				return static function (int $storeId, array $args) use ($work, $state): void {
					try {
						$work($storeId, $args);
					} catch (BitlaneError $error) {
						$state->error($storeId, $error);
						throw $error;
					}
					$state->error($storeId, null);
				};
			};
			$full = $this->fullSync();
			$changes = $this->changes();
			$check = $this->check();
			$dates = $this->dates();
			$this->runner->handle(FullSync::JOB, $sync(static fn (int $storeId, array $args) => $full->step($storeId, $args)));
			$this->runner->handle(Changes::JOB, $sync(static fn (int $storeId, array $args) => $changes->run($storeId, $args)));
			$this->runner->handle(Changes::PRODUCTS_OF, static fn (int $storeId, array $args) => $changes->runProductsOf($storeId, $args));
			$this->runner->handle(Check::JOB, static fn (int $storeId) => $check->start($storeId));
			$this->runner->handle(Check::SOURCE_JOB, $sync(static fn (int $storeId, array $args) => $check->batch($storeId, $args)));
			$this->runner->handle(Dates::JOB, static fn (int $storeId) => $dates->run($storeId));
			// Settings that change every document: hourly.
			$storeSettings = $this->storeSettings();
			$this->runner->every('store_settings', 3600, static function () use ($changes, $storeSettings): void {
				foreach ($changes->stores() as $storeId) {
					$storeSettings->check($storeId, false);
				}
			});
			// Our events and cron entry as this version needs them, should an
			// upgrade or another extension have changed them; old carts' sessions go.
			$installer = $this->installer();
			$shopEvents = $this->shopEvents();
			$this->runner->every('repair', 86400, static function () use ($installer, $shopEvents): void {
				$installer->repairEvents();
				$installer->repairCron();
				$shopEvents->prune();
			});
			$storefront = $this->storefront();
			$this->runner->handle(Storefront::JOB, static fn (int $storeId) => $storefront->refresh($storeId));
			$this->runner->handle(ShopEvents::ADDITION, static fn (int $storeId, array $args) => $shopEvents->sendAdditions($storeId, $args));
			$this->runner->handle(ShopEvents::PURCHASE, static fn (int $storeId, array $args) => $shopEvents->sendPurchase($storeId, $args));
			$this->runner->handle(ShopEvents::REFUND, static fn (int $storeId, array $args) => $shopEvents->sendRefund($storeId, $args));
		}

		return $this->runner;
	}

	/**
	 * The catalogue's changes, from OpenCart's events.
	 */
	public function events(): Events {
		return new Events($this->db, $this->changes(), $this->version);
	}

	/**
	 * The widget on the store's pages.
	 */
	public function storefront(): Storefront {
		return new Storefront($this->state(), $this->stores(), $this->api(), $this->connection(), $this->jobs());
	}

	/**
	 * Additions, purchases and refunds, sent to Bitlane.
	 */
	public function shopEvents(): ShopEvents {
		return new ShopEvents($this->db, $this->api(), $this->connection(), $this->stores(), $this->settings(), $this->storefront(), $this->jobs());
	}

	public function changes(): Changes {
		return $this->changes ??= new Changes($this->db, $this->jobs(), $this->indexer(), $this->syncState(), $this->settings(), $this->stores(), $this->connection());
	}

	public function check(): Check {
		return new Check($this->sources(), $this->settings(), $this->stores(), $this->syncState(), $this->credentials(), $this->meilisearch(), $this->fullSync(), $this->storeSettings(), $this->jobs());
	}

	public function dates(): Dates {
		return new Dates($this->db, $this->jobs(), $this->changes());
	}

	public function storeSettings(): StoreSettings {
		return new StoreSettings($this->state(), $this->settings(), $this->stores(), $this->fullSync());
	}

	public function siteDetails(): SiteDetails {
		return new SiteDetails($this->state(), $this->stores(), $this->api(), $this->connection(), $this->jobs());
	}

	public function sources(): Sources {
		return $this->sources ??= new Sources($this->db, $this->translator);
	}

	public function site(): Site {
		return new Site($this->db, $this->stores(), $this->sources(), $this->translator);
	}

	public function settings(): Settings {
		return new Settings($this->state(), $this->site());
	}

	public function syncState(): SyncState {
		return new SyncState($this->state());
	}

	public function credentials(): Credentials {
		return new Credentials($this->api(), $this->connection(), $this->state(), new Crypto());
	}

	public function fullSync(): FullSync {
		return new FullSync($this->indexer(), $this->sources(), $this->settings(), $this->site(), $this->stores(), $this->syncState(), $this->credentials(), $this->meilisearch(), $this->jobs());
	}

	public function sync(): Sync {
		return $this->sync ??= new Sync($this->fullSync(), $this->syncState(), $this->settings(), $this->credentials(), $this->check(), $this->dates(), $this->storeSettings(), $this->jobs());
	}

	private function indexer(): Indexer {
		// One per request: it keeps the storefronts it made.
		return $this->indexer ??= new Indexer($this->sources(), $this->settings(), $this->site(), $this->stores(), $this->credentials(), $this->meilisearch(), $this->syncState(), $this->app);
	}

	private function meilisearch(): Meilisearch {
		return new Meilisearch($this->http());
	}

	public function actions(): Actions {
		return new Actions($this, $this->connection(), $this->oauth(), $this->api(), $this->stores(), $this->state(), $this->translator, $this->version, $this->jobs(), $this->runner());
	}

	public static function transport(?Http $http): void {
		self::$transport = $http;
	}

	private function http(): Http {
		return $this->http ??= self::$transport ?? new Http();
	}
}
