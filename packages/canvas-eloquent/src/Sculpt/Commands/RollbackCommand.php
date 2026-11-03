<?php

	namespace Quellabs\CanvasEloquent\Sculpt\Commands;

	use Quellabs\Sculpt\ConfigurationManager;

	/**
	 * Rolls back the last batch of migrations, or a specific number of steps or batch.
	 */
	class RollbackCommand extends AbstractMigrationsCommand {
		
		/**
		 * Returns the command name.
		 * @return string
		 */
		public function getSignature(): string {
			return 'eloquent:migrate:rollback';
		}

		/**
		 * Returns the command description.
		 * @return string
		 */
		public function getDescription(): string {
			return 'Roll back the last batch of migrations.';
		}

		/**
		 * Returns detailed help text for the command.
		 * @return string
		 */
		public function getHelp(): string {
			return <<<HELP
DESCRIPTION:
    Reverses the most recent batch of migrations by running their down()
    methods, in the opposite order they were applied.

USAGE:
    php sculpt eloquent:migrate:rollback [options]

OPTIONS:
    --pretend               Show the SQL that would run without applying it
    --step=<n>               Roll back the last n migrations, regardless of batch
    --batch=<n>              Roll back every migration in the given batch number
HELP;
		}

		/**
		 * Rolls back the requested migrations.
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

			if ($config->has('step')) {
				$options['step'] = $config->getAsInt('step');
			}

			if ($config->has('batch')) {
				$options['batch'] = $config->getAsInt('batch');
			}

			try {
				$migrator->rollback([$path], $options);
				return 0;
			} catch (\Throwable $e) {
				$this->output->error($e->getMessage());
				return 1;
			}
		}
	}
