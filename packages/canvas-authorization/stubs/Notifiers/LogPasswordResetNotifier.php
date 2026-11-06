<?php

	namespace App\Notifiers;

	use App\Contracts\PasswordResetNotifierInterface;

	/**
	 * Default PasswordResetNotifierInterface implementation: writes the
	 * reset link to the PHP error log instead of sending an email.
	 *
	 * This is a placeholder so install:auth produces a working forgot-
	 * password flow out of the box — it is not meant to reach production.
	 * AuthenticationController takes a PasswordResetNotifierInterface
	 * constructor argument and only falls back to this class when the DI
	 * container has no other binding for the interface, so swap to a
	 * real mailer-backed implementation by registering a service provider
	 * that supports PasswordResetNotifierInterface — no controller edit
	 * needed.
	 */
	class LogPasswordResetNotifier implements PasswordResetNotifierInterface {

		/**
		 * @param string $username
		 * @param string $rawToken
		 */
		public function send(string $username, string $rawToken): void {
			$resetUrl = '/reset-password?token=' . urlencode($rawToken);

			error_log("Password reset requested for {$username}: {$resetUrl}");
		}
	}
