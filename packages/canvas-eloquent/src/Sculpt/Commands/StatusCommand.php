<?php

	namespace Quellabs\CanvasEloquent\Sculpt\Commands;

	use Quellabs\Sculpt\ConfigurationManager;

	/**
	 * Shows which migrations have been applied and which are pending.
	 */
	class StatusCommand extends AbstractMigrationsCommand {
		
		/**
		 * Returns the command name.
		 * @return string
		 */
		public function getSignature(): string {
			return 'eloquent:migrate:status';
		}

		/**
		 * Returns the command description.
		 * @return string
		 */
		public function getDescription(): string {
			return 'Show which migrations have been applied and which are pending.';
		}

		/**
		 * Shows the migration status.
		 * @param ConfigurationManager $config
		 * @return int
		 */
		public function execute(ConfigurationManager $config): int {
			$migrator = $this->buildMigrator();

			if ($migrator === null) {
				return 1;
			}

			$path = $this->resolveMigrationsPath();

			if ($path === null) {
				return 1;
			}

			$ran = $migrator->getRepository()->getRan();
			$batches = $migrator->getRepository()->getMigrationBatches();
			$rows = [];

			foreach ($migrator->getMigrationFiles([$path]) as $name => $file) {
				$rows[] = [$name, in_array($name, $ran, true) ? 'Yes' : 'No', (string)($batches[$name] ?? '')];
			}

			if ($rows === []) {
				$this->output->warning('No migrations found.');
				return 0;
			}

			$this->output->table(['Migration', 'Ran?', 'Batch'], $rows);
			return 0;
		}
	}
