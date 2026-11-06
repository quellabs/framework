<?php

	namespace Quellabs\CanvasAuthorization\ObjectQuel\Sculpt;

	use Quellabs\CanvasAuthorization\Sculpt\OrmAuthInstallCommand;

	class InstallAuthCommand extends OrmAuthInstallCommand {

		/**
		 * Returns the signature of this command
		 * @return string
		 */
		public function getSignature(): string {
			return "install:auth-objectquel";
		}

		/**
		 * Returns a brief description of what this command is for
		 * @return string
		 */
		public function getDescription(): string {
			return "Install authentication system with login, registration, and user management (ObjectQuel)";
		}

		/**
		 * Return stubs to copy: stub path (relative to this package's stubs/) => target path (relative to project root)
		 * @return array<string, string>
		 */
		protected function getOrmStubs(): array {
			return [
				'Entities/UserEntity.php'                  => 'src/Entities/UserEntity.php',
				'Entities/PasswordResetTokenEntity.php'    => 'src/Entities/PasswordResetTokenEntity.php',
				'Controllers/AuthenticationController.php' => 'src/Controllers/AuthenticationController.php',
				'Controllers/AuthenticatedController.php'  => 'src/Controllers/AuthenticatedController.php',
			];
		}

		/**
		 * Show next steps after successful installation
		 * @return void
		 */
		protected function showNextSteps(): void {
			$this->output->writeLn("1. Generate database migration:");
			$this->output->writeLn("   <yellow>php ./vendor/bin/sculpt make:migrations</yellow>");
			$this->output->writeLn("");
			$this->output->writeLn("2. Run the migration:");
			$this->output->writeLn("   <yellow>php ./vendor/bin/sculpt quel:migrate</yellow>");
			$this->output->writeLn("");
			$this->output->writeLn("3. Protect a controller by extending AuthenticatedController instead of BaseController:");
			$this->output->writeLn("   <yellow>class DashboardController extends App\\Controllers\\AuthenticatedController</yellow>");
			$this->output->writeLn("");
			$this->output->writeLn("   This applies session and account-eligibility checks to every action in it.");
			$this->output->writeLn("   Both aspects throw rather than redirect — src/Errors/AuthErrorHandler.php is");
			$this->output->writeLn("   what turns a failure into a redirect to /login; edit it if your login route differs.");
			$this->output->writeLn("");
			$this->output->writeLn("   Need to customize the revalidation logic beyond what AccountEligibilityAspect");
			$this->output->writeLn("   exposes? Implement BeforeAspectInterface yourself and point the controller");
			$this->output->writeLn("   at your own class instead — there is no ejectable copy to customize in place.");
			$this->output->writeLn("");
			$this->output->writeLn("4. The forgot-password flow (src/Controllers/AuthenticationController.php) ships");
			$this->output->writeLn("   with src/Notifiers/LogPasswordResetNotifier.php, which writes the reset link to");
			$this->output->writeLn("   the error log instead of emailing it. Before going to production, write a real");
			$this->output->writeLn("   mailer-backed App\\Contracts\\PasswordResetNotifierInterface implementation and");
			$this->output->writeLn("   register a DI service provider for it — AuthenticationController picks it up");
			$this->output->writeLn("   automatically, no controller edit needed.");
		}
	}
