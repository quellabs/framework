<?php
	
	namespace Quellabs\Contracts\Scheduler;
	
	/**
	 * Interface for job queues.
	 * Implementations are bound via the DI container and injected
	 * into application code that needs to dispatch jobs.
	 */
	interface QueueInterface {
		
		/**
		 * Push a job onto the queue for asynchronous execution
		 * @param QueueableInterface $job
		 * @return void
		 */
		public function push(QueueableInterface $job): void;
	}