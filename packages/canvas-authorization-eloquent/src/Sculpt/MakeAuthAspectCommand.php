<?php

	namespace Quellabs\CanvasAuthorization\Eloquent\Sculpt;

	use Quellabs\Sculpt\ConfigurationManager;
	use Quellabs\Sculpt\Contracts\StubCommand;

	/**
	 * Ejects a local, fully editable copy of AccountEligibilityAspect into the
	 * application. Only needed when the scaffolded class's constructor
	 * parameters and AccountEligibilityInterface aren't enough to express
	 * the desired revalidation behavior — most applications should edit the
	 * scaffolded src/Aspects/AccountEligibilityAspect.php directly instead.
	 */
	class MakeAuthAspectCommand extends StubCommand {

		/**
		 * Returns the signature of this command
		 * @return string
		 */
		public function getSignature(): string {
			return "make:auth-aspect-eloquent";
		}

		/**
		 * Returns a brief description of what this command is for
		 * @return string
		 */
		public function getDescription(): string {
			return "Re-create the scaffolded user revalidation aspect from a clean copy";
		}

		/**
		 * Return stubs to copy: stub path (relative to this package's stubs/) => target path (relative to project root)
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
			$this->output->writeLn("<green>Scaffolding AccountEligibilityAspect</green>");
			$this->output->writeLn("");

			$exitCode = parent::execute($config);

			if ($exitCode === 0) {
				$this->output->writeLn("");
				$this->output->writeLn("<green>Next Steps:</green>");
				$this->output->writeLn("");
				$this->output->writeLn("Point your controllers at the scaffolded copy:");
				$this->output->writeLn("   <yellow>@InterceptWith(App\\Aspects\\AccountEligibilityAspect::class, throwOnFailure=true)</yellow>");
				$this->output->writeLn("");
				$this->output->writeLn("It is your code — edit its before() method freely. It no longer");
				$this->output->writeLn("receives fixes or changes from quellabs/canvas-authorization-eloquent.");
			}

			return $exitCode;
		}
	}
