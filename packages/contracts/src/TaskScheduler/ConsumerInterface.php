<?php
	
	namespace Quellabs\Contracts\TaskScheduler;
	
	use Quellabs\Contracts\Discovery\ProviderInterface;
	
	/**
	 * Interface for TaskScheduler consumers.
	 * Implementations are discovered via the "task-scheduler" Composer family
	 * and represent a source of work (Redis queue, cron, etc.) that can be
	 * started by Sculpt via the --consumer CLI parameter.
	 */
	interface ConsumerInterface extends ProviderInterface {
		
		/**
		 * Returns the identifier used to select this consumer via CLI.
		 * e.g. "redis", "cron", "rabbitmq"
		 * @return string
		 */
		public static function getName(): string;
		
		/**
		 * Start the consumer and begin processing jobs.
		 * Each consumer is responsible for loading its own configuration.
		 * @return void
		 */
		public function run(): void;
	}