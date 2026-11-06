<?php

	namespace App\Validation;

	use Quellabs\Canvas\Validation\Contracts\ValidationInterface;
	use Quellabs\Canvas\Validation\Rules\Length;
	use Quellabs\Canvas\Validation\Rules\NotBlank;

	/**
	 * Validator class for reset-password form data
	 */
	class ResetPasswordFormValidator implements ValidationInterface {

		/**
		 * Define validation rules for reset-password form fields
		 * @return array Array of validation rules keyed by field name
		 */
		public function getRules(): array {
			return [
				// Token field validation
				'token'             => [
					new NotBlank(),  // Ensure the reset token is present
				],
				// Password field validation
				'password'          => [
					new NotBlank(),     // Ensure password is not empty
					new Length(min: 8), // Same minimum as RegistrationFormValidator
				],
				// Confirm password field validation
				'confirm_password'  => [
					new NotBlank(),  // Ensure password is not empty
				]
			];
		}
	}
