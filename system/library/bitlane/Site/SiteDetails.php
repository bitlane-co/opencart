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

namespace Bitlane\OpenCart\Site;

use Bitlane\OpenCart\Connection\BitlaneApi;
use Bitlane\OpenCart\Connection\ConnectionStore;
use Bitlane\OpenCart\Queue\Jobs;
use Bitlane\OpenCart\Support\State;

/**
 * The store's address and name in Bitlane. A store moved to another address
 * (System › Settings, a new domain) keeps its site there: the job
 * site_details sends them to PATCH /api/integrations/:id, which refuses an
 * address another site of the project has (the job then fails, in the Jobs
 * tab). Connecting with the new address would make another site.
 */
final class SiteDetails {
	public const JOB = 'site_details';
	private const NAME = 'site_details';

	public function __construct(
		private State $state,
		private Stores $stores,
		private BitlaneApi $api,
		private ConnectionStore $connection,
		private Jobs $jobs,
	) {
	}

	/**
	 * Queues the update for each connected store whose address or name
	 * changed since Bitlane got them; the first look only notes them
	 * (connecting sent them).
	 */
	public function check(): void {
		foreach ($this->stores->all() as $store) {
			if (!$this->connection->integrationId($store['id'])) {
				continue;
			}
			$kept = $this->state->json(self::NAME, $store['id']);
			if (!$kept) {
				$this->state->setJson(self::NAME, $this->details($store['id']), $store['id']);
			} elseif ($kept !== $this->details($store['id'])) {
				$this->jobs->push($store['id'], self::JOB, [], 0, self::JOB);
			}
		}
	}

	/**
	 * The job: Bitlane gets them as they are now.
	 */
	public function send(int $storeId): void {
		$id = $this->connection->integrationId($storeId);
		if (!$id) {
			return;
		}
		$details = $this->details($storeId);
		$this->api->request('PATCH', '/api/integrations/' . rawurlencode($id), $details);
		$this->state->setJson(self::NAME, $details, $storeId);
	}

	/**
	 * @return array{site_url: string, site_name: string}
	 */
	private function details(int $storeId): array {
		return ['site_url' => $this->stores->url($storeId), 'site_name' => $this->stores->name($storeId)];
	}
}
