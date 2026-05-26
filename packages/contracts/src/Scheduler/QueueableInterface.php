<?php
	
	namespace Quellabs\Contracts\Scheduler;
	
	/**
	 * Extends JobInterface for jobs that can be dispatched to a queue.
	 * Adds payload serialization so queue consumers can reconstruct the job
	 * at execution time using the DI container's make() method.
	 *
	 * Constructor parameter names must match the payload keys exactly,
	 * as the payload is passed directly to make() for reconstruction.
	 */
	interface QueueableInterface extends JobInterface {
		
		/**
		 * Return the constructor parameters to serialize when dispatching.
		 * Keys must match the job's constructor parameter names exactly.
		 * @return array<string, mixed>
		 */
		public function getPayload(): array;
	}