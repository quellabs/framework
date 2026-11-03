<?php

	namespace Quellabs\CanvasDoctrine\Sculpt\Commands;

	use Doctrine\Migrations\Tools\Console\Command\DiffCommand as DoctrineDiffCommand;
	use Quellabs\Sculpt\ConfigurationManager;
	use Quellabs\Support\OrmEngineResolver;

	/**
	 * Generates a migration by comparing the current database schema to the mapped entities.
	 */
	class MakeMigrationsCommand extends AbstractMigrationsCommand {
		/**
		 * Returns the command name: the short 'make:migrations' when Doctrine is
		 * the only ORM installed, or the 'doctrine:' prefixed form when another
		 * ORM's own make:migrations command would otherwise collide with it.
		 * @return string
		 */
		public function getSignature(): string {
			$prefix = OrmEngineResolver::count() > 1 ? 'doctrine:' : '';
			return $prefix . 'make:migrations';
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
    php sculpt {$this->getSignature()} [options]

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
