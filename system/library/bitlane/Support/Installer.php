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

use Bitlane\OpenCart\Platform\Version;
use Bitlane\OpenCart\Queue\Jobs;
use Bitlane\OpenCart\Storefront\ShopEvents;

/**
 * The extension's tables and its rows in OpenCart's `event` and `cron`
 * tables (docs/opencart-plan.md OC9, OC11, OC18). install() is safe to run
 * again: it creates what's missing and puts the rows back as this version
 * needs them, which the screen also does when it opens, since OpenCart's 4.1
 * upgrader and other extensions' installers can change them.
 */
final class Installer {
	/** Our events' codes start with it. */
	private const PREFIX = 'bitlane_';

	/** Our entry's code in OpenCart's cron table. */
	private const CRON = 'bitlane';

	public function __construct(private Database $db, private Version $version) {
	}

	public function install(): void {
		$this->db->execute('CREATE TABLE IF NOT EXISTS ' . $this->db->table(State::TABLE) . ' (
			`store_id` INT NOT NULL,
			`name` VARCHAR(191) NOT NULL,
			`value` MEDIUMTEXT NOT NULL,
			`updated_at` DATETIME NOT NULL,
			PRIMARY KEY (`store_id`, `name`)
		) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci');
		$this->db->execute('CREATE TABLE IF NOT EXISTS ' . $this->db->table(Jobs::TABLE) . ' (
			`job_id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			`store_id` INT NOT NULL,
			`type` VARCHAR(64) NOT NULL,
			`args` MEDIUMTEXT NOT NULL,
			`job_key` VARCHAR(191) NULL,
			`run_at` DATETIME NOT NULL,
			`attempts` INT NOT NULL DEFAULT 0,
			`lock_token` CHAR(32) NULL,
			`locked_until` DATETIME NULL,
			`failed_at` DATETIME NULL,
			`last_error` TEXT NULL,
			`created_at` DATETIME NOT NULL,
			PRIMARY KEY (`job_id`),
			KEY `due` (`failed_at`, `run_at`),
			KEY `store_key` (`store_id`, `job_key`),
			KEY `lock_token` (`lock_token`)
		) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci');
		// The widget's session per OpenCart session, then per order (ShopEvents).
		$this->db->execute('CREATE TABLE IF NOT EXISTS ' . $this->db->table(ShopEvents::CART_TABLE) . ' (
			`opencart_session` VARCHAR(64) NOT NULL,
			`session_id` CHAR(36) NOT NULL,
			`updated_at` DATETIME NOT NULL,
			PRIMARY KEY (`opencart_session`),
			KEY `updated_at` (`updated_at`)
		) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci');
		$this->db->execute('CREATE TABLE IF NOT EXISTS ' . $this->db->table(ShopEvents::ORDER_TABLE) . ' (
			`order_id` INT NOT NULL,
			`session_id` VARCHAR(36) NOT NULL DEFAULT \'\',
			`purchase_at` DATETIME NULL,
			`refund_at` DATETIME NULL,
			PRIMARY KEY (`order_id`)
		) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci');
		$this->repairEvents();
		$this->repairCron();
	}

	/**
	 * OpenCart's way of updating an extension uninstalls it: the tables, the
	 * connection and the jobs stay; only our rows in OpenCart's tables go.
	 */
	public function uninstall(): void {
		$this->db->execute('DELETE FROM ' . $this->db->table('event') . ' WHERE `code` LIKE ' . $this->db->quote(self::PREFIX . '%'));
		$this->db->execute('DELETE FROM ' . $this->db->table('cron') . ' WHERE `code` = ' . $this->db->quote(self::CRON));
	}

	/**
	 * Everything goes: our rows and the tables (the screen's Remove
	 * everything, after disconnecting).
	 */
	public function remove(): void {
		$this->uninstall();
		$this->db->execute('DROP TABLE IF EXISTS ' . $this->db->table(State::TABLE));
		$this->db->execute('DROP TABLE IF EXISTS ' . $this->db->table(Jobs::TABLE));
		$this->db->execute('DROP TABLE IF EXISTS ' . $this->db->table(ShopEvents::CART_TABLE));
		$this->db->execute('DROP TABLE IF EXISTS ' . $this->db->table(ShopEvents::ORDER_TABLE));
	}

	/**
	 * Our entry in OpenCart's cron table (Extensions › Cron Jobs): it runs
	 * jobs hourly when no cron of the host calls the run URL (OC11).
	 */
	public function repairCron(): void {
		$action = 'extension/bitlane/run.cron';
		$found = $this->db->row('SELECT `action`, `cycle` FROM ' . $this->db->table('cron') . ' WHERE `code` = ' . $this->db->quote(self::CRON));
		if ($found !== null && $found['action'] === $action && $found['cycle'] === 'hour') {
			return;
		}
		$this->db->execute('DELETE FROM ' . $this->db->table('cron') . ' WHERE `code` = ' . $this->db->quote(self::CRON));
		// MySQL's time, as OpenCart writes this table's.
		$this->db->execute('INSERT INTO ' . $this->db->table('cron') . ' SET `code` = ' . $this->db->quote(self::CRON)
			. ", `description` = 'Bitlane: runs the search sync’s waiting jobs', `cycle` = 'hour', `action` = " . $this->db->quote($action)
			. ", `status` = '1', `date_added` = NOW(), `date_modified` = NOW()");
	}

	/**
	 * Makes our events what this version needs: missing ones added, changed
	 * or unknown ones replaced. One a merchant disabled stays disabled.
	 *
	 * @return int how many were added or replaced
	 */
	public function repairEvents(): int {
		$expected = $this->version->events();
		$found = [];
		foreach ($this->db->rows('SELECT `event_id`, `code`, `trigger`, `action` FROM ' . $this->db->table('event') . ' WHERE `code` LIKE ' . $this->db->quote(self::PREFIX . '%')) as $row) {
			$code = (string) $row['code'];
			$want = $expected[$code] ?? null;
			if ($want === null || isset($found[$code]) || $want['trigger'] !== $row['trigger'] || $want['action'] !== $row['action']) {
				$this->db->execute('DELETE FROM ' . $this->db->table('event') . ' WHERE `event_id` = ' . (int) $row['event_id']);
				continue;
			}
			$found[$code] = true;
		}

		$added = 0;
		foreach ($expected as $code => $event) {
			if (isset($found[$code])) {
				continue;
			}
			$this->db->execute('INSERT INTO ' . $this->db->table('event') . ' SET `code` = ' . $this->db->quote($code)
				. ', `description` = ' . $this->db->quote($event['description'])
				. ', `trigger` = ' . $this->db->quote($event['trigger'])
				. ', `action` = ' . $this->db->quote($event['action'])
				. ", `status` = '1', `sort_order` = '0'");
			$added++;
		}

		return $added;
	}
}
