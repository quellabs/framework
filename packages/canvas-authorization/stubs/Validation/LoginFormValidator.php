<?php
	
	namespace App\Validation;
	
	use Quellabs\Canvas\Validation\Contracts\ValidationInterface;
	use Quellabs\Canvas\Validation\Rules\Email;
	use Quellabs\Canvas\Validation\Rules\NotBlank;
	
	/**
	 * Validator class for login form data
	 */
	class LoginFormValidator implements ValidationInterface {
		
		/**
		 * Define validation rules for login form fields
		 * @return array Array of validation rules keyed by field name
		 */
		public function getRules(): array {
			return [
				// Username field validation
				'username' => [
					new NotBlank(),  // Ensure username is not empty
					new Email(),     // Validate username is a proper email format
				],
				// Password field validation
				'password' => [
					new NotBlank(),  // Ensure password is not empty
				]
			];
		}
	}