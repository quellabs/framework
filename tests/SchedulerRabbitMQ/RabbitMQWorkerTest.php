<?php
	
	namespace Quellabs\CanvasSchedulerRabbitMQ\Tests;
	
	use PHPUnit\Framework\MockObject\MockObject;
	use PHPUnit\Framework\TestCase;
	use Psr\Log\AbstractLogger;
	use Quellabs\Canvas\Scheduler\RabbitMQ\JobEnvelope;
	use Quellabs\Canvas\Scheduler\RabbitMQ\RabbitMQQueue;
	use Quellabs\Canvas\Scheduler\RabbitMQ\RabbitMQWorker;
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
	 * A non-job class — used to verify the worker rejects it.
	 */
	class NotAJob {
	}
	
	/**
	 * Captures log records so tests can assert on them.
	 */
	class CapturingLogger extends AbstractLogger {
		public array $records = [];
		
		public function log($level, string|\Stringable $message, array $context = []): void {
			$this->records[] = ['level' => $level, 'message' => $message];
		}
		
		public function hasRecord(string $level, string $message): bool {
			foreach ($this->records as $record) {
				if ($record['level'] === $level && str_contains($record['message'], $message)) {
					return true;
				}
			}
			return false;
		}
	}

// -------------------------------------------------------------------------
// Test case
// -------------------------------------------------------------------------
	
	class RabbitMQWorkerTest extends TestCase {
		
		private RabbitMQQueue&MockObject $queue;
		private Container&MockObject $container;
		private CapturingLogger $logger;
		private RabbitMQWorker $worker;
		
		protected function setUp(): void {
			$this->queue = $this->createMock(RabbitMQQueue::class);
			$this->container = $this->createMock(Container::class);
			$this->logger = new CapturingLogger();
			
			$this->worker = new RabbitMQWorker($this->queue, $this->container, $this->logger);
		}
		
		// -------------------------------------------------------------------------
		// Helpers
		// -------------------------------------------------------------------------
		
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
		
		/**
		 * Wire up the container mock to return a fresh TrackableJob for every make() call
		 * and allow acknowledge() to be called freely. Used by tests that don't assert
		 * on those interactions specifically.
		 */
		private function stubSuccessfulJob(): TrackableJob {
			$job = new TrackableJob();
			$this->container->method('make')->willReturn($job);
			$this->queue->method('acknowledge');
			return $job;
		}
		
		// -------------------------------------------------------------------------
		// work — job limit and shutdown
		// -------------------------------------------------------------------------
		
		public function testWorkExitsAfterMaxJobsAreProcessed(): void {
			$envelope = $this->makeEnvelope();
			$job = $this->stubSuccessfulJob();
			
			// pop() returns one job then null — worker exits because processed == maxJobs
			$this->queue->method('pop')->willReturnOnConsecutiveCalls($envelope, null);
			$this->queue->method('close');
			
			$this->worker->work(maxJobs: 1, idleSleepUs: 0);
			
			$this->assertTrue($job->handled);
		}
		
		public function testWorkCallsCloseWhenDone(): void {
			$envelope = $this->makeEnvelope();
			$this->stubSuccessfulJob();
			
			$this->queue->method('pop')->willReturnOnConsecutiveCalls($envelope, null);
			$this->queue->expects($this->once())->method('close');
			
			$this->worker->work(maxJobs: 1, idleSleepUs: 0);
		}
		
		// -------------------------------------------------------------------------
		// work — successful job execution
		// -------------------------------------------------------------------------
		
		public function testWorkAcknowledgesJobOnSuccess(): void {
			$envelope = $this->makeEnvelope('success-id');
			$job = new TrackableJob();
			
			$this->queue->method('pop')->willReturnOnConsecutiveCalls($envelope, null);
			$this->container->method('make')->willReturn($job);
			$this->queue->method('close');
			
			$this->queue
				->expects($this->once())
				->method('acknowledge')
				->with($envelope);
			
			$this->worker->work(maxJobs: 1, idleSleepUs: 0);
		}
		
		public function testWorkCallsHandleOnJob(): void {
			$envelope = $this->makeEnvelope();
			$job = $this->stubSuccessfulJob();
			
			$this->queue->method('pop')->willReturnOnConsecutiveCalls($envelope, null);
			$this->queue->method('close');
			
			$this->worker->work(maxJobs: 1, idleSleepUs: 0);
			
			$this->assertTrue($job->handled);
		}
		
		public function testWorkPassesEnvelopePayloadToContainerMake(): void {
			$envelope = new JobEnvelope(TrackableJob::class, ['foo' => 'bar'], 0, 3, 60, 'id', 0);
			$job = new TrackableJob();
			
			$this->queue->method('pop')->willReturnOnConsecutiveCalls($envelope, null);
			
			$this->container
				->expects($this->once())
				->method('make')
				->with(TrackableJob::class, ['foo' => 'bar'])
				->willReturn($job);
			
			$this->queue->method('acknowledge');
			$this->queue->method('close');
			
			$this->worker->work(maxJobs: 1, idleSleepUs: 0);
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
			$this->queue->method('close');
			
			$this->queue
				->expects($this->once())
				->method('fail')
				->with($envelope);
			
			$this->worker->work(maxJobs: 1, idleSleepUs: 0);
		}
		
		public function testWorkDoesNotAcknowledgeOnJobException(): void {
			$envelope = $this->makeEnvelope();
			$job = new TrackableJob();
			$job->throwMessage = 'boom';
			
			$this->queue->method('pop')->willReturnOnConsecutiveCalls($envelope, null);
			$this->container->method('make')->willReturn($job);
			$this->queue->method('fail');
			$this->queue->method('close');
			
			$this->queue->expects($this->never())->method('acknowledge');
			
			$this->worker->work(maxJobs: 1, idleSleepUs: 0);
		}
		
		// -------------------------------------------------------------------------
		// work — non-JobInterface class
		// -------------------------------------------------------------------------
		
		public function testWorkCallsFailWhenContainerReturnsNonJobInterface(): void {
			$envelope = new JobEnvelope(TrackableJob::class, [], 0, 3, 60, 'bad-class', 0);
			
			$this->queue->method('pop')->willReturnOnConsecutiveCalls($envelope, null);
			$this->container->method('make')->willReturn(new NotAJob());
			$this->queue->method('close');
			
			$this->queue
				->expects($this->once())
				->method('fail')
				->with($envelope);
			
			$this->worker->work(maxJobs: 1, idleSleepUs: 0);
		}
		
		public function testWorkDoesNotAcknowledgeWhenContainerReturnsNonJobInterface(): void {
			$envelope = new JobEnvelope(TrackableJob::class, [], 0, 3, 60, 'bad-class', 0);
			
			$this->queue->method('pop')->willReturnOnConsecutiveCalls($envelope, null);
			$this->container->method('make')->willReturn(new NotAJob());
			$this->queue->method('fail');
			$this->queue->method('close');
			
			$this->queue->expects($this->never())->method('acknowledge');
			
			$this->worker->work(maxJobs: 1, idleSleepUs: 0);
		}
		
		// -------------------------------------------------------------------------
		// work — logging
		// -------------------------------------------------------------------------
		
		public function testWorkLogsStartAndStop(): void {
			$envelope = $this->makeEnvelope();
			$this->stubSuccessfulJob();
			
			$this->queue->method('pop')->willReturnOnConsecutiveCalls($envelope, null);
			$this->queue->method('close');
			
			$this->worker->work(maxJobs: 1, idleSleepUs: 0);
			
			$this->assertTrue($this->logger->hasRecord('info', 'Worker started'));
			$this->assertTrue($this->logger->hasRecord('info', 'Worker stopped'));
		}
		
		public function testWorkLogsJobCompletionOnSuccess(): void {
			$envelope = $this->makeEnvelope();
			$this->stubSuccessfulJob();
			
			$this->queue->method('pop')->willReturnOnConsecutiveCalls($envelope, null);
			$this->queue->method('close');
			
			$this->worker->work(maxJobs: 1, idleSleepUs: 0);
			
			$this->assertTrue($this->logger->hasRecord('info', 'Job completed successfully'));
		}
		
		public function testWorkLogsErrorOnJobFailure(): void {
			$envelope = $this->makeEnvelope();
			$job = new TrackableJob();
			$job->throwMessage = 'exploded';
			
			$this->queue->method('pop')->willReturnOnConsecutiveCalls($envelope, null);
			$this->container->method('make')->willReturn($job);
			$this->queue->method('fail');
			$this->queue->method('close');
			
			$this->worker->work(maxJobs: 1, idleSleepUs: 0);
			
			$this->assertTrue($this->logger->hasRecord('error', 'Job failed'));
		}
		
		// -------------------------------------------------------------------------
		// work — multiple jobs
		// -------------------------------------------------------------------------
		
		public function testWorkProcessesMultipleJobsInSequence(): void {
			$e1 = $this->makeEnvelope('id-1');
			$e2 = $this->makeEnvelope('id-2');
			$job = new TrackableJob();
			
			$this->queue->method('pop')->willReturnOnConsecutiveCalls($e1, $e2, null);
			$this->container->method('make')->willReturn($job);
			$this->queue->method('close');
			
			$this->queue->expects($this->exactly(2))->method('acknowledge');
			
			$this->worker->work(maxJobs: 2, idleSleepUs: 0);
		}
	}