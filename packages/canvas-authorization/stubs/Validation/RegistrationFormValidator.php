<?php
	
	namespace App\Validation;
	
	use Quellabs\Canvas\Validation\Contracts\ValidationInterface;
	use Quellabs\Canvas\Validation\Rules\Email;
	use Quellabs\Canvas\Validation\Rules\Length;
	use Quellabs\Canvas\Validation\Rules\NotBlank;
	
	/**
	 * Validator class for registration form data
	 */
	class RegistrationFormValidator implements ValidationInterface {
		
		/**
		 * Define validation rules for register form fields
		 * @return array Array of validation rules keyed by field name
		 */
		public function getRules(): array {
			return [
				// Name field validation
				'name'             => [
					new NotBlank(),  // Ensure name is not empty
				],
				// Username field validation
				'username'         => [
					new NotBlank(),  // Ensure username is not empty
					new Email(),     // Validate username is a proper email format
				],
				// Password field validation
				'password'         => [
					new NotBlank(),     // Ensure password is not empty
					new Length(min: 8), // Match the client-side minimum shown on the form
				],
				// Connfirm password field validation
				'confirm_password' => [
					new NotBlank(),  // Ensure password is not empty
				]
			];
		}
	}