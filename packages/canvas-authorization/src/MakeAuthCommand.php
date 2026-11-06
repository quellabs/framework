<?php
	
	namespace Quellabs\CanvasAuthorization;
	
	use Quellabs\Sculpt\ConfigurationManager;
	use Quellabs\Sculpt\Contracts\StubCommand;
	
	class MakeAuthCommand extends StubCommand {
		
		/**
		 * Returns the signature of this command
		 * @return string
		 */
		public function getSignature(): string {
			return "install:auth";
		}
		
		/**
		 * Returns a brief description of what this command is for
		 * @return string
		 */
		public function getDescription(): string {
			return "Install authentication system with login, registration, and user management";
		}

		/**
		 * Execute the command, then show next steps on success.
		 * @param ConfigurationManager $config
		 * @return int Exit code (0 = success, 1 = error)
		 */
		public function execute(ConfigurationManager $config): int {
			$this->output->writeLn("<green>Installing Authentication System</green>");
			$this->output->writeLn("");
			
			$exitCode = parent::execute($config);
			
			if ($exitCode === 0) {
				$this->showNextSteps();
			}
			
			return $exitCode;
		}
		
		
		/**
		 * Maps a template engine name to the file extension its templates use.
		 */
		private const array TEMPLATE_EXTENSIONS = [
			'smarty' => 'tpl',
			'blade'  => 'blade.php',
			'latte'  => 'latte',
			'php'    => 'php',
			'twig'   => 'twig',
		];

		/**
		 * Returns token list
		 * @return array|string[]
		 */
		protected function getTokens(): array {
			return array_merge(parent::getTokens(), [
				'{{ template_ext }}' => $this->resolveTemplateExtension(),
			]);
		}

		/**
		 * Return stubs to copy: stub path (relative to package stubs/) => target path (relative to project root)
		 * @return array<string, string>
		 */
		protected function getStubs(): array {
			$engine = $this->resolveTemplateEngine();
			$ext = $this->resolveTemplateExtension();

			return [
				'Controllers/AuthenticationController.php'     => 'src/Controllers/AuthenticationController.php',
				'Controllers/AuthenticatedController.php'      => 'src/Controllers/AuthenticatedController.php',
				'Validation/LoginFormValidator.php'            => 'src/Validation/LoginFormValidator.php',
				'Validation/RegistrationFormValidator.php'     => 'src/Validation/RegistrationFormValidator.php',
				'Validation/ForgotPasswordFormValidator.php'   => 'src/Validation/ForgotPasswordFormValidator.php',
				'Validation/ResetPasswordFormValidator.php'    => 'src/Validation/ResetPasswordFormValidator.php',
				'Entities/UserEntity.php'                      => 'src/Entities/UserEntity.php',
				'Entities/PasswordResetTokenEntity.php'        => 'src/Entities/PasswordResetTokenEntity.php',
				'Contracts/PasswordResetNotifierInterface.php' => 'src/Contracts/PasswordResetNotifierInterface.php',
				'Notifiers/LogPasswordResetNotifier.php'       => 'src/Notifiers/LogPasswordResetNotifier.php',
				'Exceptions/UserCreationException.php'         => 'src/Exceptions/UserCreationException.php',
				'Errors/AuthErrorHandler.php'                  => 'src/Errors/AuthErrorHandler.php',
				"/templates/{$engine}/login.{$ext}"             => "templates/login.{$ext}",
				"/templates/{$engine}/registration_form.{$ext}" => "templates/registration_form.{$ext}",
				"/templates/{$engine}/forgot_password.{$ext}"   => "templates/forgot_password.{$ext}",
				"/templates/{$engine}/reset_password.{$ext}"    => "templates/reset_password.{$ext}",
			];
		}
		
		/**
		 * Resolves the file extension used by the configured template engine.
		 * @return string
		 */
		private function resolveTemplateExtension(): string {
			return self::TEMPLATE_EXTENSIONS[$this->resolveTemplateEngine()] ?? 'tpl';
		}

		/**
		 * Show next steps after successful installation
		 * @return void
		 */
		private function showNextSteps(): void {
			$this->output->writeLn("");
			$this->output->writeLn("<green>Next Steps:</green>");
			$this->output->writeLn("");
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
			$this->output->writeLn("   exposes? Eject a local, fully editable copy instead:");
			$this->output->writeLn("   <yellow>php ./vendor/bin/sculpt make:auth-aspect</yellow>");
			$this->output->writeLn("");
			$this->output->writeLn("4. The forgot-password flow (src/Controllers/AuthenticationController.php) ships");
			$this->output->writeLn("   with src/Notifiers/LogPasswordResetNotifier.php, which writes the reset link to");
			$this->output->writeLn("   the error log instead of emailing it. Before going to production, write a real");
			$this->output->writeLn("   mailer-backed App\\Contracts\\PasswordResetNotifierInterface implementation and");
			$this->output->writeLn("   register a DI service provider for it — AuthenticationController picks it up");
			$this->output->writeLn("   automatically, no controller edit needed.");
		}
	}