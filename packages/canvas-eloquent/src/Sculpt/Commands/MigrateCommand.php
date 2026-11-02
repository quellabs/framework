<?php

	namespace Quellabs\CanvasEloquent\Sculpt\Commands;

	use Quellabs\Sculpt\ConfigurationManager;

	/**
	 * Runs pending migrations.
	 */
	class MigrateCommand extends AbstractMigrationsCommand {
		/**
		 * Returns the command name.
		 * @return string
		 */
		public function getSignature(): string {
			return 'eloquent:migrate';
		}

		/**
		 * Returns the command description.
		 * @return string
		 */
		public function getDescription(): string {
			return 'Run pending migrations.';
		}

		/**
		 * Returns detailed help text for the command.
		 * @return string
		 */
		public function getHelp(): string {
			return <<<HELP
DESCRIPTION:
    Runs migrations tracked in the migrations table that have not yet
    been applied, in the order their filenames sort.

USAGE:
    php sculpt eloquent:migrate [options]

OPTIONS:
    --pretend               Show the SQL that would run without applying it
    --step                  Run each migration as its own batch, so it can be rolled back individually
HELP;
		}

		/**
		 * Runs the pending migrations.
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

			$options = [];

			if ($config->hasFlag('pretend')) {
				$options['pretend'] = true;
			}

			if ($config->hasFlag('step')) {
				$options['step'] = true;
			}

			try {
				$migrator->run([$path], $options);
				return 0;
			} catch (\Throwable $e) {
				$this->output->error($e->getMessage());
				return 1;
			}
		}
	}
