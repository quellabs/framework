<?php

	namespace Quellabs\CanvasDoctrine\Sculpt\Commands;

	use Doctrine\Migrations\DependencyFactory;
	use Quellabs\CanvasDoctrine\Sculpt\MigrationsDependencyFactory;
	use Quellabs\Sculpt\Contracts\CommandBase;
	use Symfony\Component\Console\Command\Command as SymfonyCommand;
	use Symfony\Component\Console\Input\ArrayInput;
	use Symfony\Component\Console\Output\ConsoleOutput as SymfonyConsoleOutput;

	/**
	 * Shared plumbing for Sculpt commands that delegate to a doctrine/migrations
	 * console command. Doctrine's own commands own the migration logic
	 * (planning, execution, schema diffing); these wrappers only build the
	 * DependencyFactory from canvas-doctrine's config and relay the result,
	 * writing straight to the real terminal rather than through Sculpt's own
	 * ConsoleOutput, since Doctrine's SymfonyStyle output already handles
	 * tables, confirmations, and formatting correctly on its own.
	 */
	abstract class AbstractMigrationsCommand extends CommandBase {
		/**
		 * Builds the DependencyFactory from the application's doctrine config.
		 * @return DependencyFactory|null Null when the config is invalid; the error is already printed
		 */
		protected function buildDependencyFactory(): ?DependencyFactory {
			try {
				return MigrationsDependencyFactory::create($this->getProvider()->getConfig());
			} catch (\InvalidArgumentException $e) {
				$this->output->error($e->getMessage());
				return null;
			}
		}

		/**
		 * Runs a doctrine/migrations console command and relays its exit code.
		 * @param SymfonyCommand $command
		 * @param array<string, mixed> $input
		 * @param bool $interactive False skips any confirmation prompt the command would otherwise show
		 * @return int
		 */
		protected function runDoctrineCommand(SymfonyCommand $command, array $input, bool $interactive = true): int {
			try {
				$consoleInput = new ArrayInput($input);
				$consoleInput->setInteractive($interactive);
				return $command->run($consoleInput, new SymfonyConsoleOutput());
			} catch (\Throwable $e) {
				$this->output->error($e->getMessage());
				return 1;
			}
		}
	}
