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

namespace Bitlane\OpenCart\Queue;

use Bitlane\OpenCart\Support\BitlaneError;
use Bitlane\OpenCart\Support\Lock;
use Bitlane\OpenCart\Support\State;

/**
 * Runs due jobs, one runner at a time (a MySQL named lock), for a time
 * budget and while memory lasts (docs/opencart-plan.md OC11). A cron calls
 * url() every minute (OpenCart has no console); the Bitlane screen runs jobs
 * while it's open, and OpenCart's own cron runs them hourly as a fallback.
 * Store requests never run them.
 *
 * A job's type names its handler. A handler that throws BitlaneError is
 * retried with backoff (Retry); one that gives up is kept a week with its
 * error (Jobs::fail(), the Jobs tab), and each run prunes older ones. Work
 * that recurs (every()) runs at the start of a run when it's due.
 */
final class Runner {
	/** @var array<string, callable(int, array<mixed>): void> */
	private array $handlers = [];

	/** @var array<string, array{seconds: int, work: callable(): void}> */
	private array $recurring = [];

	/**
	 * @param string $catalogUrl store 0's address, where the run URL is
	 * @param callable(string): void $log OpenCart's error log
	 */
	public function __construct(
		private Jobs $jobs,
		private Lock $lock,
		private State $state,
		private string $catalogUrl,
		private $log,
	) {
	}

	/**
	 * @param callable(int, array<mixed>): void $handler takes the store and the job's arguments
	 */
	public function handle(string $type, callable $handler): void {
		$this->handlers[$type] = $handler;
	}

	/**
	 * Work done at most every $seconds, at the start of a run (queuing jobs,
	 * mostly).
	 *
	 * @param callable(): void $work
	 */
	public function every(string $name, int $seconds, callable $work): void {
		$this->recurring[$name] = ['seconds' => $seconds, 'work' => $work];
	}

	/**
	 * Runs due jobs for up to $seconds; how many ran (0 when another runner is
	 * at work).
	 */
	public function run(int $seconds): int {
		$ran = 0;
		$this->lock->attempt('runner', function () use ($seconds, &$ran) {
			$this->state->set('runner_at', (string) time());
			$this->jobs->prune();
			$this->recur();
			$until = microtime(true) + $seconds;
			$memory = $this->memoryLimit();
			while (microtime(true) < $until && ($memory <= 0 || memory_get_usage(true) < $memory * 0.7)) {
				$job = $this->jobs->claim();
				if ($job === null) {
					break;
				}
				$this->execute($job);
				$ran++;
			}
		});

		return $ran;
	}

	/**
	 * The runner's URL, for a cron: the storefront's route with the install's
	 * secret.
	 */
	public function url(): string {
		return rtrim($this->catalogUrl, '/') . '/index.php?route=extension/bitlane/run&key=' . $this->secret();
	}

	public function secret(): string {
		$secret = (string) $this->state->get('runner_secret');
		if ($secret === '') {
			$secret = bin2hex(random_bytes(16));
			$this->state->set('runner_secret', $secret);
		}

		return $secret;
	}

	/**
	 * When a runner last started; 0 never.
	 */
	public function lastRun(): int {
		return (int) $this->state->get('runner_at');
	}

	/**
	 * The cron line: every minute, the URL (a run takes up to a minute).
	 */
	public function cronLine(): string {
		return '* * * * * curl -fsS --max-time 120 "' . $this->url() . '" >/dev/null 2>&1';
	}

	/**
	 * When the oldest job waiting to run became due; 0 when none waits.
	 */
	public function waitingSince(): int {
		return $this->jobs->oldestDue();
	}

	private function recur(): void {
		foreach ($this->recurring as $name => $recurring) {
			$key = 'every:' . $name;
			if ((int) $this->state->get($key) > time() - $recurring['seconds']) {
				continue;
			}
			$this->state->set($key, (string) time());
			try {
				($recurring['work'])();
			} catch (\Throwable $e) {
				($this->log)('Bitlane: ' . $name . ': ' . $e->getMessage() . ' at ' . $e->getFile() . ':' . $e->getLine());
			}
		}
	}

	/**
	 * @param array{id: int, store: int, type: string, args: array<mixed>, attempts: int} $job
	 */
	private function execute(array $job): void {
		$handler = $this->handlers[$job['type']] ?? null;
		if ($handler === null) {
			$this->jobs->done($job['id']);

			return;
		}
		try {
			$handler($job['store'], $job['args']);
			$this->jobs->done($job['id']);
		} catch (BitlaneError $error) {
			$this->failed($job, $error);
		} catch (\Throwable $e) {
			($this->log)('Bitlane: ' . $job['type'] . ': ' . $e->getMessage() . ' at ' . $e->getFile() . ':' . $e->getLine());
			$this->failed($job, new BitlaneError('unexpected', $e->getMessage()));
		}
	}

	/**
	 * @param array{id: int, store: int, type: string, args: array<mixed>, attempts: int} $job
	 */
	private function failed(array $job, BitlaneError $error): void {
		$delay = Retry::after($error, $job['attempts']);
		if ($delay === null) {
			$this->jobs->fail($job['id'], $error->getMessage());
		} else {
			$this->jobs->retry($job['id'], $delay, $error->getMessage());
		}
	}

	/**
	 * PHP's memory limit in bytes; 0 for none.
	 */
	private function memoryLimit(): int {
		$limit = trim((string) ini_get('memory_limit'));
		if ($limit === '' || $limit === '-1') {
			return 0;
		}
		$units = ['k' => 1024, 'm' => 1048576, 'g' => 1073741824];

		return (int) $limit * ($units[strtolower(substr($limit, -1))] ?? 1);
	}
}
