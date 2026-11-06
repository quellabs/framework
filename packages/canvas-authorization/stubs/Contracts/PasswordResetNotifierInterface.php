<?php

	namespace App\Contracts;

	/**
	 * Delivers a password reset link/token to a user. Kept as a narrow
	 * interface, separate from AuthenticationController itself, so the
	 * actual delivery mechanism can be swapped (e.g. for a real mailer)
	 * without touching token generation, expiry, or validation logic.
	 */
	interface PasswordResetNotifierInterface {

		/**
		 * @param string $username Address/username to notify, as stored on
		 *                         the account (see UserEntity::getUsername()).
		 * @param string $rawToken The unhashed token — this is the only
		 *                         place the raw value is ever exposed outside
		 *                         AuthenticationController; it is never persisted (see
		 *                         PasswordResetTokenEntity).
		 */
		public function send(string $username, string $rawToken): void;
	}