<?php

	namespace Quellabs\CanvasAuthorization;

	use Quellabs\Sculpt\ConfigurationManager;
	use Quellabs\Sculpt\Contracts\StubCommand;

	/**
	 * Ejects a local, fully editable copy of AccountEligibilityAspect into the
	 * application. Only needed when Quellabs\CanvasAuthorization\AccountEligibilityAspect's
	 * constructor parameters and AccountEligibilityInterface aren't enough to express
	 * the desired revalidation behavior — most applications should use that class
	 * directly instead of running this command.
	 */
	class MakeAuthAspectCommand extends StubCommand {

		/**
		 * Returns the signature of this command
		 * @return string
		 */
		public function getSignature(): string {
			return "make:auth-aspect";
		}

		/**
		 * Returns a brief description of what this command is for
		 * @return string
		 */
		public function getDescription(): string {
			return "Eject a local, editable copy of the user revalidation aspect";
		}

		/**
		 * Return stubs to copy: stub path (relative to package stubs/) => target path (relative to project root)
		 * @return array<string, string>
		 */
		protected function getStubs(): array {
			return [
				'Aspects/AccountEligibilityAspect.php' => 'src/Aspects/AccountEligibilityAspect.php',
			];
		}

		/**
		 * Execute the command, then show next steps on success.
		 * @param ConfigurationManager $config
		 * @return int Exit code (0 = success, 1 = error)
		 */
		public function execute(ConfigurationManager $config): int {
			$this->output->writeLn("<green>Ejecting AccountEligibilityAspect</green>");
			$this->output->writeLn("");

			$exitCode = parent::execute($config);

			if ($exitCode === 0) {
				$this->output->writeLn("");
				$this->output->writeLn("<green>Next Steps:</green>");
				$this->output->writeLn("");
				$this->output->writeLn("Point your controllers at the ejected copy instead of the package class:");
				$this->output->writeLn("   <yellow>@InterceptWith(App\\Aspects\\AccountEligibilityAspect::class, throwOnFailure=true)</yellow>");
				$this->output->writeLn("");
				$this->output->writeLn("It is now your code — edit its before() method freely. It no longer");
				$this->output->writeLn("receives fixes or changes from quellabs/canvas-authorization.");
			}

			return $exitCode;
		}
	}
