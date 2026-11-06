<?php

	namespace Quellabs\CanvasSchedulerRedis\Tests;

	use PHPUnit\Framework\MockObject\MockObject;
	use PHPUnit\Framework\TestCase;
	use Quellabs\Canvas\Scheduler\Redis\JobEnvelope;
	use Quellabs\Canvas\Scheduler\Redis\RedisQueue;
	use Quellabs\Canvas\Scheduler\Redis\RedisWorker;
	use Quellabs\Contracts\Scheduler\JobInterface;
	use Quellabs\Contracts\Scheduler\QueueableInterface;
	use Quellabs\DependencyInjection\Container;

	// -------------------------------------------------------------------------
	// Stubs
	// -------------------------------------------------------------------------

	/**
	 * A job that records whether handle() was called and optionally throws.
	 */
	class TrackableJob implements JobInterface, QueueableInterface {
		public bool $handled = false;
		public ?string $throwMessage = null;

		public function handle(): void {
			$this->handled = true;

			if ($this->throwMessage !== null) {
				throw new \RuntimeException($this->throwMessage);
			}
		}

		public function getPayload(): array {
			return [];
		}

		public function getMaxRetries(): int {
			return 3;
		}

		public function getTimeout(): int {
			return 60;
		}
	}

	/**
	 * Implements only the broader JobInterface (e.g. a Cron task would) - not
	 * QueueableInterface - to prove the worker rejects it rather than accepting
	 * any JobInterface implementor, which would let a queue message remotely
	 * instantiate a class never designed to be reconstructed this way.
	 */
	class JobInterfaceOnlyJob implements JobInterface {
		public function handle(): void {
		}

		public function getMaxRetries(): int {
			return 3;
		}

		public function getTimeout(): int {
			return 60;
		}
	}

	// -------------------------------------------------------------------------
	// Test case
	// -------------------------------------------------------------------------

	class RedisWorkerTest extends TestCase {

		private RedisQueue&MockObject $queue;
		private Container&MockObject $container;
		private RedisWorker $worker;

		protected function setUp(): void {
			$this->queue = $this->createMock(RedisQueue::class);
			$this->container = $this->createMock(Container::class);

			$this->worker = new RedisWorker($this->queue, $this->container);
		}

		private function makeEnvelope(string $id = 'test-id', int $attempts = 0): JobEnvelope {
			return new JobEnvelope(
				class: TrackableJob::class,
				payload: [],
				attempts: $attempts,
				maxRetries: 3,
				timeout: 60,
				id: $id,
				queuedAt: 0
			);
		}

		// -------------------------------------------------------------------------
		// work — successful job execution
		// -------------------------------------------------------------------------

		public function testWorkAcknowledgesJobOnSuccess(): void {
			$envelope = $this->makeEnvelope('success-id');
			$job = new TrackableJob();

			$this->queue->method('pop')->willReturnOnConsecutiveCalls($envelope, null);
			$this->container->method('make')->willReturn($job);

			$this->queue
				->expects($this->once())
				->method('acknowledge')
				->with($envelope);

			$this->worker->work(maxJobs: 1, timeout: 0);

			$this->assertTrue($job->handled);
		}

		// -------------------------------------------------------------------------
		// work — failed job execution
		// -------------------------------------------------------------------------

		public function testWorkCallsFailOnJobException(): void {
			$envelope = $this->makeEnvelope('fail-id');
			$job = new TrackableJob();
			$job->throwMessage = 'something broke';

			$this->queue->method('pop')->willReturnOnConsecutiveCalls($envelope, null);
			$this->container->method('make')->willReturn($job);

			$this->queue
				->expects($this->once())
				->method('fail')
				->with($envelope);

			$this->worker->work(maxJobs: 1, timeout: 0);
		}

		// -------------------------------------------------------------------------
		// work — non-QueueableInterface class
		// -------------------------------------------------------------------------

		public function testWorkCallsFailWhenContainerReturnsAJobInterfaceImplementorThatIsNotQueueable(): void {
			// Implementing the broader JobInterface (as a Cron task would) must not be
			// enough to pass the worker's check - only QueueableInterface should.
			$envelope = $this->makeEnvelope('bad-class');

			$this->queue->method('pop')->willReturnOnConsecutiveCalls($envelope, null);
			$this->container->method('make')->willReturn(new JobInterfaceOnlyJob());

			$this->queue
				->expects($this->once())
				->method('fail')
				->with($envelope);

			$this->queue->expects($this->never())->method('acknowledge');

			$this->worker->work(maxJobs: 1, timeout: 0);
		}
	}
