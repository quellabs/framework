<?php
	
	namespace Quellabs\Canvas\Scheduler\RabbitMQ;
	
	use Psr\Log\LoggerInterface;
	use Psr\Log\NullLogger;
	use Quellabs\Contracts\Scheduler\JobInterface;
	use Quellabs\DependencyInjection\Container;
	
	/**
	 * Long-running worker process that consumes jobs from a RabbitMQ queue.
	 *
	 * Lifecycle:
	 * - Polls basic_get in a tight loop, sleeping briefly when the queue is empty
	 * - Reconstructs each job via the DI container using the envelope payload
	 * - Executes the job's handle() method
	 * - Acknowledges on success, fails (with retry logic) on exception
	 * - Exits cleanly after processing $maxJobs jobs, or on SIGTERM/SIGINT
	 *   (Supervisord restarts it immediately, sidestepping PHP memory leak concerns)
	 *
	 * Polling vs. basic_consume:
	 *   basic_consume would require callback inversion (the broker drives the loop),
	 *   which makes signal handling and the maxJobs limit harder to integrate cleanly.
	 *   basic_get keeps the worker in control of its own loop at the cost of a small
	 *   busy-poll overhead when the queue is empty. The $idleSleepUs parameter controls
	 *   how long the worker sleeps between empty polls (default: 200 ms).
	 *
	 * Signal handling:
	 * - SIGTERM / SIGINT set a flag that is checked between jobs
	 * - The current job always runs to completion before the worker exits
	 */
	class RabbitMQWorker {
		
		private RabbitMQQueue $queue;
		private Container $container;
		private LoggerInterface $logger;
		
		/**
		 * Set to true when a signal is received — worker exits after current job
		 * @var bool
		 */
		private bool $shouldStop = false;
		
		/**
		 * RabbitMQWorker constructor
		 * @param RabbitMQQueue $queue
		 * @param Container $container DI container used to reconstruct job instances
		 * @param LoggerInterface|null $logger
		 */
		public function __construct(
			RabbitMQQueue $queue,
			Container $container,
			?LoggerInterface $logger = null
		) {
			$this->queue = $queue;
			$this->container = $container;
			$this->logger = $logger ?? new NullLogger();
		}
		
		/**
		 * Start the worker loop.
		 * Runs until $maxJobs have been processed or a stop signal is received.
		 * @param int $maxJobs Maximum jobs to process before exiting (0 = unlimited)
		 * @param int $idleSleepUs Microseconds to sleep between empty polls (default: 200 000 = 200 ms)
		 * @return void
		 */
		public function work(int $maxJobs = 500, int $idleSleepUs = 200_000): void {
			$this->registerSignalHandlers();
			
			$processed = 0;
			$this->logger->info("Worker started", ['max_jobs' => $maxJobs]);
			
			while (!$this->shouldStop) {
				// Exit if we've hit the job limit — Supervisord will restart us
				if ($maxJobs > 0 && $processed >= $maxJobs) {
					$this->logger->info("Worker reached max jobs limit, exiting cleanly", ['processed' => $processed]);
					break;
				}
				
				// Dispatch pending signals before polling
				if (function_exists('pcntl_signal_dispatch')) {
					pcntl_signal_dispatch();
				}
				
				// Poll for a job; sleep briefly and loop again if the queue is empty
				$envelope = $this->queue->pop();
				
				if ($envelope === null) {
					usleep($idleSleepUs);
					continue;
				}
				
				$this->processEnvelope($envelope);
				$processed++;
			}
			
			$this->queue->close();
			$this->logger->info("Worker stopped", ['processed' => $processed]);
		}
		
		/**
		 * Process a single job envelope — reconstruct, execute, acknowledge or fail
		 * @param JobEnvelope $envelope
		 * @return void
		 */
		private function processEnvelope(JobEnvelope $envelope): void {
			$this->logger->info("Processing job", [
				'id'       => $envelope->id,
				'class'    => $envelope->class,
				'attempts' => $envelope->attempts,
			]);
			
			$startTime = microtime(true);
			
			try {
				// Reconstruct job via DI container using payload as constructor parameters
				$job = $this->container->make($envelope->class, $envelope->payload);
				
				// Validate the reconstructed instance implements JobInterface
				if (!$job instanceof JobInterface) {
					throw new \RuntimeException("Class '{$envelope->class}' does not implement JobInterface");
				}
				
				// Execute the job
				$job->handle();
				
				// Acknowledge successful execution
				$this->queue->acknowledge($envelope);
				
				$duration = round((microtime(true) - $startTime) * 1000);
				$this->logger->info("Job completed successfully", [
					'id'          => $envelope->id,
					'class'       => $envelope->class,
					'duration_ms' => $duration,
				]);
				
			} catch (\Throwable $e) {
				$duration = round((microtime(true) - $startTime) * 1000);
				
				$this->logger->error("Job failed", [
					'id'          => $envelope->id,
					'class'       => $envelope->class,
					'attempts'    => $envelope->attempts,
					'duration_ms' => $duration,
					'error'       => $e->getMessage(),
				]);
				
				// Delegate retry/failed logic to the queue
				$this->queue->fail($envelope);
			}
		}
		
		/**
		 * Register SIGTERM and SIGINT handlers so the worker exits cleanly
		 * between jobs rather than mid-execution.
		 * @return void
		 */
		private function registerSignalHandlers(): void {
			if (!function_exists('pcntl_signal')) {
				return;
			}
			
			$handler = function (int $signal): void {
				$this->logger->info("Signal received, stopping after current job", ['signal' => $signal]);
				$this->shouldStop = true;
			};
			
			pcntl_signal(SIGTERM, $handler);
			pcntl_signal(SIGINT, $handler);
		}
	}