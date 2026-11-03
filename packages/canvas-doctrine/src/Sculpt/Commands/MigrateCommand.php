<?php

	namespace Quellabs\CanvasDoctrine\Sculpt\Commands;

	use Doctrine\Migrations\Tools\Console\Command\MigrateCommand as DoctrineMigrateCommand;
	use Quellabs\Sculpt\ConfigurationManager;

	/**
	 * Runs pending migrations, or migrates to a specific version.
	 */
	class MigrateCommand extends AbstractMigrationsCommand {
		/**
		 * Returns the command name.
		 * @return string
		 */
		public function getSignature(): string {
			return 'doctrine:migrate';
		}

		/**
		 * Returns the command description.
		 * @return string
		 */
		public function getDescription(): string {
			return 'Run pending migrations, or migrate to a specific version.';
		}

		/**
		 * Returns detailed help text for the command.
		 * @return string
		 */
		public function getHelp(): string {
			return <<<HELP
DESCRIPTION:
    Runs migrations tracked in the configured migrations_table (default
    doctrine_migrations), up to the given version (or the latest one, by
    default). Pass a version below the current one to roll back to it.
    Prompts for confirmation unless --force is passed.

USAGE:
    php sculpt doctrine:migrate [version] [options]

ARGUMENTS:
    version                Version FQCN or alias (first, prev, next, latest) to migrate to. Default: latest

OPTIONS:
    --dry-run               Preview the migration without applying it
    --force, -f              Skip the confirmation prompt
HELP;
		}

		/**
		 * Runs the migration.
		 * @param ConfigurationManager $config
		 * @return int
		 */
		public function execute(ConfigurationManager $config): int {
			$dependencyFactory = $this->buildDependencyFactory();

			if ($dependencyFactory === null) {
				return 1;
			}

			$input = ['version' => $config->getPositional(0, 'latest')];

			if ($config->hasFlag('dry-run')) {
				$input['--dry-run'] = true;
			}

			$force = $config->hasFlag('force') || $config->hasFlag('f');
			return $this->runDoctrineCommand(new DoctrineMigrateCommand($dependencyFactory), $input, !$force);
		}
	}
