<?php

	namespace Quellabs\CanvasEloquent\Sculpt\Commands;

	use Illuminate\Database\Migrations\Migrator;
	use Quellabs\CanvasEloquent\Sculpt\MigratorFactory;
	use Quellabs\Sculpt\Contracts\CommandBase;
	use Symfony\Component\Console\Output\ConsoleOutput as SymfonyConsoleOutput;

	/**
	 * Shared plumbing for Sculpt commands that drive illuminate/database's
	 * Migrator directly. Illuminate's own migration logic (running, rolling
	 * back, tracking batches) stays owned by the Migrator and its repository;
	 * these wrappers only build it from canvas-eloquent's config and let the
	 * Migrator write straight to the real terminal, since its own output
	 * components already handle task/list formatting correctly on their own.
	 */
	abstract class AbstractMigrationsCommand extends CommandBase {
		
		/**
		 * Builds the Migrator from the application's eloquent config, wired to
		 * write directly to the real terminal.
		 * @return Migrator|null Null when the config is invalid; the error is already printed
		 */
		protected function buildMigrator(): ?Migrator {
			try {
				$migrator = MigratorFactory::create($this->getProvider()->getConfig());
				$migrator->setOutput(new SymfonyConsoleOutput());
				return $migrator;
			} catch (\InvalidArgumentException $e) {
				$this->output->error($e->getMessage());
				return null;
			}
		}

		/**
		 * Resolves the configured migrations directory.
		 * @return string|null Null when the config is invalid; the error is already printed
		 */
		protected function resolveMigrationsPath(): ?string {
			try {
				return MigratorFactory::resolveMigrationsPath($this->getProvider()->getConfig());
			} catch (\InvalidArgumentException $e) {
				$this->output->error($e->getMessage());
				return null;
			}
		}
	}
