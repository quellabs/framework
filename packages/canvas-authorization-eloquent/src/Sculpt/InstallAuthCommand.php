<?php

	namespace Quellabs\CanvasAuthorization\Eloquent\Sculpt;

	use Quellabs\CanvasAuthorization\Sculpt\OrmAuthInstallCommand;

	class InstallAuthCommand extends OrmAuthInstallCommand {

		/**
		 * Returns the signature of this command
		 * @return string
		 */
		public function getSignature(): string {
			return "install:auth-eloquent";
		}

		/**
		 * Returns a brief description of what this command is for
		 * @return string
		 */
		public function getDescription(): string {
			return "Install authentication system with login, registration, and user management (Eloquent)";
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
				'Aspects/AccountEligibilityAspect.php'     => 'src/Aspects/AccountEligibilityAspect.php',
			];
		}

		/**
		 * Show next steps after successful installation
		 * @return void
		 */
		protected function showNextSteps(): void {
			$this->output->writeLn("1. canvas-eloquent does not bundle a migration generator — create the");
			$this->output->writeLn("   'users' and 'password_reset_tokens' tables (see src/Entities/UserEntity.php");
			$this->output->writeLn("   and src/Entities/PasswordResetTokenEntity.php for the expected columns)");
			$this->output->writeLn("   with your own migration tooling.");
			$this->output->writeLn("");
			$this->output->writeLn("2. Protect a controller by extending AuthenticatedController instead of BaseController:");
			$this->output->writeLn("   <yellow>class DashboardController extends App\\Controllers\\AuthenticatedController</yellow>");
			$this->output->writeLn("");
			$this->output->writeLn("   This applies session and account-eligibility checks to every action in it.");
			$this->output->writeLn("   Both aspects throw rather than redirect — src/Errors/AuthErrorHandler.php is");
			$this->output->writeLn("   what turns a failure into a redirect to /login; edit it if your login route differs.");
			$this->output->writeLn("");
			$this->output->writeLn("   AccountEligibilityAspect was scaffolded as your own code at");
			$this->output->writeLn("   src/Aspects/AccountEligibilityAspect.php — edit its before() method directly");
			$this->output->writeLn("   to customize the revalidation logic; it receives no fixes from the package.");
			$this->output->writeLn("");
			$this->output->writeLn("3. The forgot-password flow (src/Controllers/AuthenticationController.php) ships");
			$this->output->writeLn("   with src/Notifiers/LogPasswordResetNotifier.php, which writes the reset link to");
			$this->output->writeLn("   the error log instead of emailing it. Before going to production, write a real");
			$this->output->writeLn("   mailer-backed App\\Contracts\\PasswordResetNotifierInterface implementation and");
			$this->output->writeLn("   register a DI service provider for it — AuthenticationController picks it up");
			$this->output->writeLn("   automatically, no controller edit needed.");
		}
	}
