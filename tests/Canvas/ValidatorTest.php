<?php
	
	namespace Quellabs\Canvas\Tests\Validation;
	
	use PHPUnit\Framework\TestCase;
	use Quellabs\Canvas\Validation\Contracts\ValidationInterface;
	use Quellabs\Canvas\Validation\Contracts\ValidationRuleInterface;
	use Quellabs\Canvas\Validation\Rules\Email;
	use Quellabs\Canvas\Validation\Rules\Length;
	use Quellabs\Canvas\Validation\Rules\NotBlank;
	use Quellabs\Canvas\Validation\Validator;
	
	/**
	 * Unit tests for Validator.
	 *
	 * Covers flat field validation, nested field structures (dot notation),
	 * multiple validators per field, error accumulation, and invalid validator handling.
	 */
	class ValidatorTest extends TestCase {
		
		private Validator $validator;
		
		protected function setUp(): void {
			$this->validator = new Validator();
		}
		
		// -------------------------------------------------------------------------
		// Helpers — anonymous ValidationInterface implementations
		// -------------------------------------------------------------------------
		
		private function rules(array $rulesArray): ValidationInterface {
			return new class($rulesArray) implements ValidationInterface {
				public function __construct(private array $r) {}
				public function getRules(): array { return $this->r; }
			};
		}
		
		// -------------------------------------------------------------------------
		// Flat field validation — passing
		// -------------------------------------------------------------------------
		
		public function testValidDataProducesNoErrors(): void {
			$errors = $this->validator->validate(
				['email' => 'user@example.com'],
				$this->rules(['email' => [new Email()]])
			);
			$this->assertEmpty($errors);
		}
		
		public function testMultipleValidFieldsProduceNoErrors(): void {
			$errors = $this->validator->validate(
				['name' => 'Floris', 'email' => 'floris@quellabs.com'],
				$this->rules([
					'name'  => [new NotBlank()],
					'email' => [new Email()],
				])
			);
			$this->assertEmpty($errors);
		}
		
		// -------------------------------------------------------------------------
		// Flat field validation — failing
		// -------------------------------------------------------------------------
		
		public function testFailingValidatorProducesErrorForField(): void {
			$errors = $this->validator->validate(
				['email' => 'not-an-email'],
				$this->rules(['email' => [new Email()]])
			);
			$this->assertArrayHasKey('email', $errors);
			$this->assertNotEmpty($errors['email']);
		}
		
		public function testErrorMessageIsNonEmptyString(): void {
			$errors = $this->validator->validate(
				['email' => 'bad'],
				$this->rules(['email' => [new Email()]])
			);
			$this->assertIsString($errors['email'][0]);
			$this->assertNotEmpty($errors['email'][0]);
		}
		
		public function testMissingFieldTreatedAsNull(): void {
			// NotBlank fails on null
			$errors = $this->validator->validate(
				[],
				$this->rules(['name' => [new NotBlank()]])
			);
			$this->assertArrayHasKey('name', $errors);
		}
		
		// -------------------------------------------------------------------------
		// Multiple validators per field
		// -------------------------------------------------------------------------
		
		public function testMultipleValidatorsAllRunWhenFirstPasses(): void {
			// NotBlank passes for 'hi', but Length(min=5) fails
			$errors = $this->validator->validate(
				['name' => 'hi'],
				$this->rules(['name' => [new NotBlank(), new Length(5)]])
			);
			$this->assertArrayHasKey('name', $errors);
		}
		
		public function testMultipleErrorsCollectedForSameField(): void {
			// Both NotBlank (fails on '') AND Email (not checked — empty passes Email)
			// Use a blank value that fails NotBlank, and add a second always-failing rule
			$alwaysFail = new class implements ValidationRuleInterface {
				public function validate(mixed $value): bool { return false; }
				public function getError(): string { return 'always fails'; }
			};
			
			$errors = $this->validator->validate(
				['field' => '   '],
				$this->rules(['field' => [new NotBlank(), $alwaysFail]])
			);
			
			// NotBlank fails on whitespace-only; alwaysFail also fails
			$this->assertCount(2, $errors['field']);
		}
		
		public function testMultipleFieldsCanFailIndependently(): void {
			$errors = $this->validator->validate(
				['email' => 'bad', 'name' => ''],
				$this->rules([
					'email' => [new Email()],
					'name'  => [new NotBlank()],
				])
			);
			$this->assertArrayHasKey('email', $errors);
			$this->assertArrayHasKey('name', $errors);
		}
		
		// -------------------------------------------------------------------------
		// Single validator (non-array) normalised to array
		// -------------------------------------------------------------------------
		
		public function testSingleValidatorNotWrappedInArrayIsAccepted(): void {
			$errors = $this->validator->validate(
				['email' => 'bad'],
				$this->rules(['email' => new Email()])
			);
			$this->assertArrayHasKey('email', $errors);
		}
		
		// -------------------------------------------------------------------------
		// Nested field structures (dot notation)
		// -------------------------------------------------------------------------
		
		public function testNestedFieldUseDotNotationKey(): void {
			$errors = $this->validator->validate(
				['address' => ['city' => '']],
				$this->rules([
					'address' => [
						'city' => [new NotBlank()],
					],
				])
			);
			$this->assertArrayHasKey('address.city', $errors);
		}
		
		public function testNestedFieldPassesWhenValueValid(): void {
			$errors = $this->validator->validate(
				['address' => ['city' => 'Haarlem']],
				$this->rules([
					'address' => [
						'city' => [new NotBlank()],
					],
				])
			);
			$this->assertEmpty($errors);
		}
		
		public function testMissingNestedParentTreatedAsEmptyArray(): void {
			// 'address' key absent — nested validator should still run with null city
			$errors = $this->validator->validate(
				[],
				$this->rules([
					'address' => [
						'city' => [new NotBlank()],
					],
				])
			);
			$this->assertArrayHasKey('address.city', $errors);
		}
		
		// -------------------------------------------------------------------------
		// Invalid validator throws
		// -------------------------------------------------------------------------
		
		public function testInvalidValidatorThrowsInvalidArgumentException(): void {
			$this->expectException(\InvalidArgumentException::class);
			$this->validator->validate(
				['field' => 'value'],
				$this->rules(['field' => ['not_a_validator_object']])
			);
		}
		
		// -------------------------------------------------------------------------
		// Return type
		// -------------------------------------------------------------------------
		
		public function testValidateReturnsArray(): void {
			$result = $this->validator->validate([], $this->rules([]));
			$this->assertIsArray($result);
		}
		
		public function testValidateReturnsEmptyArrayWhenNoRules(): void {
			$result = $this->validator->validate(['foo' => 'bar'], $this->rules([]));
			$this->assertEmpty($result);
		}
	}