<?php

	namespace Quellabs\CanvasEloquent\Sculpt\Commands;

	use Illuminate\Database\Migrations\MigrationCreator;
	use Illuminate\Filesystem\Filesystem;
	use Quellabs\Sculpt\ConfigurationManager;
	use Quellabs\Support\OrmEngineResolver;

	/**
	 * Generates a blank migration class in the configured migrations directory,
	 * from illuminate/database's own stubs.
	 */
	class MakeBlankMigrationCommand extends AbstractMigrationsCommand {
		/**
		 * Returns the command name: the short 'make:blank-migration' when
		 * Eloquent is the only ORM installed, or the 'eloquent:' prefixed form
		 * when another ORM's own make:blank-migration command would otherwise
		 * collide with it.
		 * @return string
		 */
		public function getSignature(): string {
			$prefix = OrmEngineResolver::count() > 1 ? 'eloquent:' : '';
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
		 * Returns detailed help text for the command.
		 * @return string
		 */
		public function getHelp(): string {
			return <<<HELP
DESCRIPTION:
    Writes a new migration file into the configured migrations directory,
    named <timestamp>_<name>.php. Pass --create or --table to pre-fill the
    Schema::create()/Schema::table() boilerplate for the given table.

USAGE:
    php sculpt {$this->getSignature()} <name> [options]

ARGUMENTS:
    name                    Migration name, e.g. create_posts_table

OPTIONS:
    --create=<table>        Pre-fill a Schema::create() for the given table
    --table=<table>         Pre-fill a Schema::table() update for the given table
HELP;
		}

		/**
		 * Generates the migration file.
		 * @param ConfigurationManager $config
		 * @return int
		 */
		public function execute(ConfigurationManager $config): int {
			$name = $config->getPositional(0);

			if ($name === null || $name === '') {
				$this->output->error('A migration name is required.');
				return 1;
			}

			$path = $this->resolveMigrationsPath();

			if ($path === null) {
				return 1;
			}

			$create = $config->getAsString('create');
			$table = $create !== '' ? $create : $config->getAsString('table');

			$creator = new MigrationCreator(new Filesystem(), '');

			try {
				$file = $creator->create($name, $path, $table !== '' ? $table : null, $create !== '');
			} catch (\Throwable $e) {
				$this->output->error($e->getMessage());
				return 1;
			}

			$this->output->success("Created migration: {$file}");
			return 0;
		}
	}
