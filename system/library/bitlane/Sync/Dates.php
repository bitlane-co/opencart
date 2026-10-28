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

namespace Bitlane\OpenCart\Sync;

use Bitlane\OpenCart\Queue\Jobs;
use Bitlane\OpenCart\Support\Database;

/**
 * What changes with the date, which no event announces (docs/opencart-plan.md
 * OC10): specials and discounts start and end on a day, and a product becomes
 * available on its date_available. OpenCart compares those days with MySQL's
 * NOW(), so they change at MySQL's midnight, whatever PHP's time zone: the
 * job dates runs just after it and syncs the products whose day it is.
 */
final class Dates {
	public const JOB = 'dates';

	public function __construct(private Database $db, private Jobs $jobs, private Changes $changes) {
	}

	/**
	 * Queues the next run, a minute after MySQL's next midnight; one already
	 * queued is kept.
	 */
	public function schedule(int $storeId): void {
		$row = $this->db->row('SELECT TIMESTAMPDIFF(SECOND, NOW(), DATE(NOW()) + INTERVAL 1 DAY) AS seconds, DATE(NOW() + INTERVAL 1 DAY) AS day');
		$this->jobs->push($storeId, self::JOB, [], (int) ($row['seconds'] ?? 86400) + 60, 'dates:' . ($row['day'] ?? ''));
	}

	/**
	 * The job: today's starts and ends, synced now, and tomorrow's run.
	 */
	public function run(int $storeId): void {
		$this->schedule($storeId);
		$ids = [];
		foreach (['product_special', 'product_discount'] as $table) {
			if ($this->db->exists($table)) {
				foreach ($this->db->rows('SELECT DISTINCT `product_id` FROM ' . $this->db->table($table) . ' WHERE `date_start` = CURDATE() OR `date_end` = CURDATE()') as $row) {
					$ids[] = (int) $row['product_id'];
				}
			}
		}
		foreach ($this->db->rows('SELECT `product_id` FROM ' . $this->db->table('product') . " WHERE `date_available` = CURDATE() AND `status` = '1'") as $row) {
			$ids[] = (int) $row['product_id'];
		}
		if ($ids) {
			$this->changes->products(array_values(array_unique($ids)));
			$this->changes->flush();
		}
	}
}
