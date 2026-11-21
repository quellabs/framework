<?php

	namespace Quellabs\Canvas\Tests\Fixtures\Scheduler;

	use Quellabs\Contracts\Scheduler\JobInterface;

	/**
	 * Job carrying a WeakMap-backed dependency, mirroring real tasks whose
	 * constructor-injected services transitively hold an EntityManager.
	 * Writes a marker file on handle() so tests can confirm it actually ran
	 * inside the subprocess, not just that the subprocess exited cleanly.
	 */
	class WeakMapDependencyJob implements JobInterface {

		private \WeakMap $state;

		public function __construct() {
			$this->state = new \WeakMap();
			$this->state[$this] = true;
		}

		/**
		 * @return void
		 */
		public function handle(): void {
			$markerFile = getenv('WEAKMAP_DEPENDENCY_JOB_MARKER');

			if ($markerFile !== false) {
				file_put_contents($markerFile, 'ran');
			}
		}

		/**
		 * @return int
		 */
		public function getTimeout(): int {
			return 5;
		}

		/**
		 * @return int
		 */
		public function getMaxRetries(): int {
			return 0;
		}
	}
