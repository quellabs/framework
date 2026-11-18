<?php

	namespace Quellabs\CanvasAuthorization;

	/**
	 * Resolves the ORM-agnostic auth stubs (validation, notifiers, error
	 * handling, templates) that every ORM package's install:auth-{orm}
	 * command installs in addition to its own ORM-specific stubs. Lives here
	 * so the shared stub files stay physically in this package instead of
	 * being duplicated into canvas-authorization-doctrine/-eloquent/-objectquel.
	 */
	class SharedAuthStubsInstaller {

		/**
		 * Return the shared stubs to copy: stub path (relative to this
		 * package's own stubs/) => target path (relative to project root).
		 * @param string $templateEngine The configured template engine (e.g. 'smarty', 'blade')
		 * @param string $templateExtension The file extension used by that template engine
		 * @return array<string, string>
		 */
		public static function getStubs(string $templateEngine, string $templateExtension): array {
			return [
				'Validation/LoginFormValidator.php'               => 'src/Validation/LoginFormValidator.php',
				'Validation/RegistrationFormValidator.php'        => 'src/Validation/RegistrationFormValidator.php',
				'Validation/ForgotPasswordFormValidator.php'      => 'src/Validation/ForgotPasswordFormValidator.php',
				'Validation/ResetPasswordFormValidator.php'       => 'src/Validation/ResetPasswordFormValidator.php',
				'Contracts/PasswordResetNotifierInterface.php'    => 'src/Contracts/PasswordResetNotifierInterface.php',
				'Notifiers/LogPasswordResetNotifier.php'          => 'src/Notifiers/LogPasswordResetNotifier.php',
				'Exceptions/UserCreationException.php'            => 'src/Exceptions/UserCreationException.php',
				'Errors/AuthErrorHandler.php'                     => 'src/Errors/AuthErrorHandler.php',
				"templates/{$templateEngine}/login.{$templateExtension}"             => "templates/login.{$templateExtension}",
				"templates/{$templateEngine}/registration_form.{$templateExtension}" => "templates/registration_form.{$templateExtension}",
				"templates/{$templateEngine}/forgot_password.{$templateExtension}"   => "templates/forgot_password.{$templateExtension}",
				"templates/{$templateEngine}/reset_password.{$templateExtension}"    => "templates/reset_password.{$templateExtension}",
			];
		}

		/**
		 * Absolute path to this package's own stubs/ directory.
		 * @return string
		 */
		public static function getStubRoot(): string {
			return __DIR__ . '/../stubs/';
		}
	}
