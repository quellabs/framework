<?php

	namespace Quellabs\CanvasDoctrine\Sculpt\Commands;

	use Doctrine\Migrations\Tools\Console\Command\StatusCommand as DoctrineStatusCommand;
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
			return 'doctrine:migrate:status';
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
			$dependencyFactory = $this->buildDependencyFactory();

			if ($dependencyFactory === null) {
				return 1;
			}

			return $this->runDoctrineCommand(new DoctrineStatusCommand($dependencyFactory), []);
		}
	}
