<?php

	namespace Quellabs\CanvasDoctrine\Sculpt\Commands;

	use Doctrine\Migrations\Tools\Console\Command\GenerateCommand as DoctrineGenerateCommand;
	use Quellabs\Sculpt\ConfigurationManager;
	use Quellabs\Support\OrmEngineResolver;

	/**
	 * Generates a blank migration class in the configured migrations directory.
	 */
	class MakeBlankMigrationCommand extends AbstractMigrationsCommand {
		/**
		 * Returns the command name: the short 'make:blank-migration' when
		 * Doctrine is the only ORM installed, or the 'doctrine:' prefixed form
		 * when another ORM's own make:blank-migration command would otherwise
		 * collide with it.
		 * @return string
		 */
		public function getSignature(): string {
			$prefix = OrmEngineResolver::count() > 1 ? 'doctrine:' : '';
			return $prefix . 'make:blank-migration';
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
