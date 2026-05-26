<?php
	
	namespace App\Tasks;
	
	use Quellabs\Support\ComposerUtils;
	use Quellabs\Canvas\Scheduler\Cron\AbstractTask;
	
	/**
	 * Example cron task that runs every minute.
	 * Copy this file to src/Tasks/ and modify to suit your needs.
	 */
	class TestTask extends AbstractTask {
		
		/**
		 * Execute the task
		 * @return void
		 */
		function handle(): void {
			file_put_contents(
				ComposerUtils::getProjectRoot() . '/storage/test-task.log',
				date('Y-m-d H:i:s') . " TestTask executed\n",
				FILE_APPEND
			);
		}
		
		/**
		 * Run every minute
		 * @return string
		 */
		public function getSchedule(): string {
			return '* * * * *';
		}
		
		/**
		 * Unique identifier for this task
		 * @return string
		 */
		public function getName(): string {
			return 'test-task';
		}
		
		/**
		 * Human-readable description
		 * @return string
		 */
		public function getDescription(): string {
			return 'Test task that writes a timestamp to a log file every minute';
		}
		
		/**
		 * This task is active
		 * @return bool
		 */
		public function enabled(): bool {
			return true;
		}
	}