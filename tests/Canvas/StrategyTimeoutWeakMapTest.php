<?php

	namespace Quellabs\Canvas\Tests;

	use PHPUnit\Framework\TestCase;
	use Psr\Log\NullLogger;
	use Quellabs\Canvas\Scheduler\Runner\StrategyTimeout;
	use Quellabs\Canvas\Tests\Fixtures\Scheduler\WeakMapDependencyJob;

	/**
	 * Regression test for issue #436: StrategyTimeout used to serialize() the
	 * whole job object to hand it to its subprocess, which throws outright
	 * for any job holding a WeakMap-backed dependency (e.g. the EntityManager).
	 * The strategy now only passes the job's class name across and resolves
	 * a fresh instance inside the subprocess via the DI container.
	 */
	class StrategyTimeoutWeakMapTest extends TestCase {

		private ?string $markerFile = null;

		protected function tearDown(): void {
			if ($this->markerFile !== null && file_exists($this->markerFile)) {
				unlink($this->markerFile);
			}

			putenv('WEAKMAP_DEPENDENCY_JOB_MARKER');
		}

		public function testRunsAJobThatHoldsAWeakMapWithoutSerializationError(): void {
			$this->markerFile = tempnam(sys_get_temp_dir(), 'weakmap_job_marker_');
			putenv('WEAKMAP_DEPENDENCY_JOB_MARKER=' . $this->markerFile);

			$strategy = new StrategyTimeout(5, new NullLogger());
			$strategy->run(new WeakMapDependencyJob());

			$this->assertSame('ran', file_get_contents($this->markerFile));
		}
	}
