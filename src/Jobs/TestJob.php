<?php
	
	namespace App\Jobs;
	
	use Quellabs\Support\ComposerUtils;
	use Quellabs\Contracts\Scheduler\QueueableInterface;
	
	/**
	 * Test job that writes a timestamp to a log file.
	 * Used to verify the Redis queue consumer is working correctly.
	 */
	class TestJob implements QueueableInterface {
		
		/**
		 * Execute the job
		 * @return void
		 */
		public function handle(): void {
			file_put_contents(
				ComposerUtils::getProjectRoot() . '/storage/test-job.log',
				date('Y-m-d H:i:s') . " TestJob executed\n",
				FILE_APPEND
			);
		}
		
		/**
		 * No payload — this job has no constructor parameters
		 * @return array<string, mixed>
		 */
		public function getPayload(): array {
			return [];
		}
		
		/**
		 * 30 second timeout
		 * @return int
		 */
		public function getTimeout(): int {
			return 30;
		}
		
		/**
		 * Retry once on failure
		 * @return int
		 */
		public function getMaxRetries(): int {
			return 1;
		}
	}