<?php

	namespace App\Validation;

	use Quellabs\Canvas\Validation\Contracts\ValidationInterface;
	use Quellabs\Canvas\Validation\Contracts\ValidationRuleInterface;
	use Quellabs\Canvas\Validation\Rules\Email;
	use Quellabs\Canvas\Validation\Rules\NotBlank;

	/**
	 * Validator class for forgot-password form data
	 */
	class ForgotPasswordFormValidator implements ValidationInterface {

		/**
		 * Define validation rules for forgot-password form fields
		 * @return array<string, ValidationRuleInterface|array<int, ValidationRuleInterface>>
		 */
		public function getRules(): array {
			return [
				// Username field validation
				'username' => [
					new NotBlank(),  // Ensure username is not empty
					new Email(),     // Validate username is a proper email format
				],
			];
		}
	}
