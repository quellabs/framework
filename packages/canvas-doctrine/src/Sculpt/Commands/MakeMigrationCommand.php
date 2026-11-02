<?php

	namespace Quellabs\CanvasDoctrine\Sculpt\Commands;

	use Doctrine\Migrations\Tools\Console\Command\GenerateCommand as DoctrineGenerateCommand;
	use Quellabs\Sculpt\ConfigurationManager;

	/**
	 * Generates a blank migration class in the configured migrations directory.
	 */
	class MakeMigrationCommand extends AbstractMigrationsCommand {
		/**
		 * Returns the command name.
		 * @return string
		 */
		public function getSignature(): string {
			return 'doctrine:make:migration';
		}

		/**
		 * Returns the command description.
		 * @return string
		 */
		public function getDescription(): string {
			return 'Generate a blank migration class.';
		}

		/**
		 * Generates the migration class.
		 * @param ConfigurationManager $config
		 * @return int
		 */
		public function execute(ConfigurationManager $config): int {
			$dependencyFactory = $this->buildDependencyFactory();

			if ($dependencyFactory === null) {
				return 1;
			}

			return $this->runDoctrineCommand(new DoctrineGenerateCommand($dependencyFactory), []);
		}
	}
