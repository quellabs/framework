<?php

	namespace Quellabs\CanvasDoctrine\Sculpt\Commands;

	use Doctrine\Migrations\Tools\Console\Command\DiffCommand as DoctrineDiffCommand;
	use Quellabs\Sculpt\ConfigurationManager;

	/**
	 * Generates a migration by comparing the current database schema to the mapped entities.
	 */
	class DiffCommand extends AbstractMigrationsCommand {
		/**
		 * Returns the command name.
		 * @return string
		 */
		public function getSignature(): string {
			return 'doctrine:migrations:diff';
		}

		/**
		 * Returns the command description.
		 * @return string
		 */
		public function getDescription(): string {
			return 'Generate a migration by comparing the current database to the mapped entities.';
		}

		/**
		 * Returns detailed help text for the command.
		 * @return string
		 */
		public function getHelp(): string {
			return <<<HELP
DESCRIPTION:
    Compares the current database schema to the mapped entities and generates
    a migration class containing the SQL needed to bring the database in line.

USAGE:
    php sculpt doctrine:migrations:diff [options]

OPTIONS:
    --allow-empty-diff      Do not fail when no schema changes are detected
HELP;
		}

		/**
		 * Generates the diff migration.
		 * @param ConfigurationManager $config
		 * @return int
		 */
		public function execute(ConfigurationManager $config): int {
			$dependencyFactory = $this->buildDependencyFactory();

			if ($dependencyFactory === null) {
				return 1;
			}

			$input = [];

			if ($config->hasFlag('allow-empty-diff')) {
				$input['--allow-empty-diff'] = true;
			}

			return $this->runDoctrineCommand(new DoctrineDiffCommand($dependencyFactory), $input);
		}
	}
