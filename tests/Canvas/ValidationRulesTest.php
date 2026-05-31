<?php
	
	namespace Quellabs\Canvas\Tests;
	
	use PHPUnit\Framework\TestCase;
	use Quellabs\Canvas\Validation\Rules\AtLeastOneOf;
	use Quellabs\Canvas\Validation\Rules\Date;
	use Quellabs\Canvas\Validation\Rules\Email;
	use Quellabs\Canvas\Validation\Rules\Length;
	use Quellabs\Canvas\Validation\Rules\NotBlank;
	use Quellabs\Canvas\Validation\Rules\NotHTML;
	use Quellabs\Canvas\Validation\Rules\NotLongWord;
	use Quellabs\Canvas\Validation\Rules\PhoneNumber;
	use Quellabs\Canvas\Validation\Rules\RegExp;
	use Quellabs\Canvas\Validation\Rules\Type;
	use Quellabs\Canvas\Validation\Rules\ValueIn;
	
	/**
	 * Unit tests for all Canvas validation rules.
	 *
	 * Convention used throughout:
	 * - Empty string and null pass every rule (except NotBlank) — rules are composable,
	 *   and mandatory-field enforcement is delegated to NotBlank.
	 * - Non-string inputs fail rules that are string-only (Email, Length, etc.)
	 *   unless the rule explicitly permits other types (Type, AtLeastOneOf).
	 */
	class ValidationRulesTest extends TestCase {
		
		// -------------------------------------------------------------------------
		// NotBlank
		// -------------------------------------------------------------------------
		
		public function testNotBlankPassesNonEmptyString(): void {
			$rule = new NotBlank();
			$this->assertTrue($rule->validate('hello'));
		}
		
		public function testNotBlankFailsEmptyString(): void {
			$rule = new NotBlank();
			$this->assertFalse($rule->validate(''));
		}
		
		public function testNotBlankFailsWhitespaceOnlyString(): void {
			$rule = new NotBlank();
			$this->assertFalse($rule->validate('   '));
		}
		
		public function testNotBlankFailsNull(): void {
			$rule = new NotBlank();
			$this->assertFalse($rule->validate(null));
		}
		
		public function testNotBlankFailsNonStringValue(): void {
			$rule = new NotBlank();
			// Integers are not strings — the rule rejects them
			$this->assertFalse($rule->validate(42));
		}
		
		public function testNotBlankReturnsDefaultErrorMessage(): void {
			$rule = new NotBlank();
			$this->assertNotEmpty($rule->getError());
		}
		
		public function testNotBlankReturnsCustomErrorMessage(): void {
			$rule = new NotBlank('Field is required.');
			$this->assertSame('Field is required.', $rule->getError());
		}
		
		// -------------------------------------------------------------------------
		// Email
		// -------------------------------------------------------------------------
		
		public function testEmailPassesValidAddress(): void {
			$rule = new Email();
			$this->assertTrue($rule->validate('user@example.com'));
		}
		
		public function testEmailPassesSubdomainAddress(): void {
			$rule = new Email();
			$this->assertTrue($rule->validate('user@mail.example.co.uk'));
		}
		
		public function testEmailFailsMissingAtSign(): void {
			$rule = new Email();
			$this->assertFalse($rule->validate('userexample.com'));
		}
		
		public function testEmailFailsMissingDomain(): void {
			$rule = new Email();
			$this->assertFalse($rule->validate('user@'));
		}
		
		public function testEmailPassesEmptyString(): void {
			// Empty is allowed; use NotBlank separately for mandatory fields
			$rule = new Email();
			$this->assertTrue($rule->validate(''));
		}
		
		public function testEmailPassesNull(): void {
			$rule = new Email();
			$this->assertTrue($rule->validate(null));
		}
		
		public function testEmailFailsNonStringValue(): void {
			$rule = new Email();
			$this->assertFalse($rule->validate(12345));
		}
		
		public function testEmailReturnsDefaultErrorMessage(): void {
			$rule = new Email();
			$this->assertNotEmpty($rule->getError());
		}
		
		// -------------------------------------------------------------------------
		// Length
		// -------------------------------------------------------------------------
		
		public function testLengthPassesStringWithinRange(): void {
			$rule = new Length(2, 10);
			$this->assertTrue($rule->validate('hello'));
		}
		
		public function testLengthPassesStringAtMinBoundary(): void {
			$rule = new Length(5, 10);
			$this->assertTrue($rule->validate('hello'));
		}
		
		public function testLengthPassesStringAtMaxBoundary(): void {
			$rule = new Length(1, 5);
			$this->assertTrue($rule->validate('hello'));
		}
		
		public function testLengthFailsStringTooShort(): void {
			$rule = new Length(5, 10);
			$this->assertFalse($rule->validate('hi'));
		}
		
		public function testLengthFailsStringTooLong(): void {
			$rule = new Length(1, 3);
			$this->assertFalse($rule->validate('hello'));
		}
		
		public function testLengthPassesEmptyString(): void {
			$rule = new Length(5, 10);
			$this->assertTrue($rule->validate(''));
		}
		
		public function testLengthPassesNull(): void {
			$rule = new Length(5, 10);
			$this->assertTrue($rule->validate(null));
		}
		
		public function testLengthFailsNonStringValue(): void {
			$rule = new Length(1, 10);
			$this->assertFalse($rule->validate(42));
		}
		
		public function testLengthWithMinOnlyPassesLongString(): void {
			$rule = new Length(3);
			$this->assertTrue($rule->validate('hello'));
		}
		
		public function testLengthWithMinOnlyFailsShortString(): void {
			$rule = new Length(10);
			$this->assertFalse($rule->validate('hi'));
		}
		
		public function testLengthErrorMessageContainsMinValue(): void {
			$rule = new Length(5, 10);
			$rule->validate('hi'); // trigger the min violation
			$this->assertStringContainsString('5', $rule->getError());
		}
		
		public function testLengthErrorMessageContainsMaxValue(): void {
			$rule = new Length(1, 3);
			$rule->validate('toolong'); // trigger the max violation
			$this->assertStringContainsString('3', $rule->getError());
		}
		
		public function testLengthCustomMessageIsReturned(): void {
			$rule = new Length(5, 10, 'Wrong length.');
			$rule->validate('hi');
			$this->assertSame('Wrong length.', $rule->getError());
		}
		
		// -------------------------------------------------------------------------
		// NotHTML
		// -------------------------------------------------------------------------
		
		public function testNotHtmlPassesPlainText(): void {
			$rule = new NotHTML();
			$this->assertTrue($rule->validate('Hello World'));
		}
		
		public function testNotHtmlFailsStringWithHtmlTag(): void {
			$rule = new NotHTML();
			$this->assertFalse($rule->validate('<p>Hello</p>'));
		}
		
		public function testNotHtmlFailsStringWithEncodedHtml(): void {
			$rule = new NotHTML();
			// The rule decodes &lt; → < before checking
			$this->assertFalse($rule->validate('&lt;p&gt;Hello&lt;/p&gt;'));
		}
		
		public function testNotHtmlPassesEmptyString(): void {
			$rule = new NotHTML();
			$this->assertTrue($rule->validate(''));
		}
		
		public function testNotHtmlPassesNull(): void {
			$rule = new NotHTML();
			$this->assertTrue($rule->validate(null));
		}
		
		public function testNotHtmlFailsNonStringValue(): void {
			$rule = new NotHTML();
			$this->assertFalse($rule->validate(123));
		}
		
		public function testNotHtmlReturnsDefaultErrorMessage(): void {
			$rule = new NotHTML();
			$this->assertNotEmpty($rule->getError());
		}
		
		// -------------------------------------------------------------------------
		// NotLongWord
		// -------------------------------------------------------------------------
		
		public function testNotLongWordPassesShortWord(): void {
			$rule = new NotLongWord(30);
			$this->assertTrue($rule->validate('short'));
		}
		
		public function testNotLongWordFailsWordExceedingLimit(): void {
			$rule = new NotLongWord(5);
			$this->assertFalse($rule->validate('toolongword'));
		}
		
		public function testNotLongWordPassesWhenAllWordsAreWithinLimit(): void {
			$rule = new NotLongWord(10);
			$this->assertTrue($rule->validate('hello world'));
		}
		
		public function testNotLongWordFailsIfAnyWordExceedsLimit(): void {
			$rule = new NotLongWord(5);
			$this->assertFalse($rule->validate('hi averylongword ok'));
		}
		
		public function testNotLongWordPassesEmptyString(): void {
			$rule = new NotLongWord(5);
			$this->assertTrue($rule->validate(''));
		}
		
		public function testNotLongWordPassesNull(): void {
			$rule = new NotLongWord(5);
			$this->assertTrue($rule->validate(null));
		}
		
		public function testNotLongWordFailsNonStringValue(): void {
			$rule = new NotLongWord(30);
			$this->assertFalse($rule->validate(12345));
		}
		
		public function testNotLongWordErrorContainsLength(): void {
			$rule = new NotLongWord(7);
			$this->assertStringContainsString('7', $rule->getError());
		}
		
		// -------------------------------------------------------------------------
		// PhoneNumber
		// -------------------------------------------------------------------------
		
		public function testPhoneNumberPassesStandardNumber(): void {
			$rule = new PhoneNumber();
			$this->assertTrue($rule->validate('+31 20 123 4567'));
		}
		
		public function testPhoneNumberPassesNumberWithDashes(): void {
			$rule = new PhoneNumber();
			$this->assertTrue($rule->validate('020-123-4567'));
		}
		
		public function testPhoneNumberFailsStringWithLetters(): void {
			$rule = new PhoneNumber();
			$this->assertFalse($rule->validate('call-me'));
		}
		
		public function testPhoneNumberPassesEmptyString(): void {
			$rule = new PhoneNumber();
			$this->assertTrue($rule->validate(''));
		}
		
		public function testPhoneNumberPassesNull(): void {
			$rule = new PhoneNumber();
			$this->assertTrue($rule->validate(null));
		}
		
		public function testPhoneNumberFailsNonStringValue(): void {
			$rule = new PhoneNumber();
			$this->assertFalse($rule->validate(12345678));
		}
		
		// -------------------------------------------------------------------------
		// Date
		// -------------------------------------------------------------------------
		
		public function testDatePassesIso8601Date(): void {
			$rule = new Date();
			$this->assertTrue($rule->validate('2024-05-01'));
		}
		
		public function testDatePassesSlashSeparatedDate(): void {
			$rule = new Date();
			$this->assertTrue($rule->validate('01/05/2024'));
		}
		
		public function testDatePassesIso8601WithMilliseconds(): void {
			$rule = new Date();
			$this->assertTrue($rule->validate('2024-05-01T13:45:00.000Z'));
		}
		
		public function testDateFailsGibberishString(): void {
			$rule = new Date();
			$this->assertFalse($rule->validate('not-a-date'));
		}
		
		public function testDatePassesEmptyString(): void {
			$rule = new Date();
			$this->assertTrue($rule->validate(''));
		}
		
		public function testDatePassesNull(): void {
			$rule = new Date();
			$this->assertTrue($rule->validate(null));
		}
		
		public function testDateFailsNonStringValue(): void {
			$rule = new Date();
			$this->assertFalse($rule->validate(20240501));
		}
		
		public function testDateReturnsDefaultErrorMessage(): void {
			$rule = new Date();
			$this->assertNotEmpty($rule->getError());
		}
		
		// -------------------------------------------------------------------------
		// RegExp
		// -------------------------------------------------------------------------
		
		public function testRegExpPassesMatchingValue(): void {
			$rule = new RegExp('/^\d{4}$/');
			$this->assertTrue($rule->validate('2024'));
		}
		
		public function testRegExpFailsNonMatchingValue(): void {
			$rule = new RegExp('/^\d{4}$/');
			$this->assertFalse($rule->validate('abc'));
		}
		
		public function testRegExpPassesEmptyString(): void {
			$rule = new RegExp('/^\d{4}$/');
			$this->assertTrue($rule->validate(''));
		}
		
		public function testRegExpPassesNull(): void {
			$rule = new RegExp('/^\d{4}$/');
			$this->assertTrue($rule->validate(null));
		}
		
		public function testRegExpFailsNonStringValue(): void {
			$rule = new RegExp('/^\d{4}$/');
			$this->assertFalse($rule->validate(2024));
		}
		
		public function testRegExpReturnsDefaultErrorMessage(): void {
			$rule = new RegExp('/^\d{4}$/');
			$this->assertNotEmpty($rule->getError());
		}
		
		public function testRegExpReturnsCustomErrorMessage(): void {
			$rule = new RegExp('/^\d{4}$/', 'Must be a 4-digit year.');
			$this->assertSame('Must be a 4-digit year.', $rule->getError());
		}
		
		// -------------------------------------------------------------------------
		// ValueIn
		// -------------------------------------------------------------------------
		
		public function testValueInPassesValueInAllowedList(): void {
			$rule = new ValueIn(['red', 'green', 'blue']);
			$this->assertTrue($rule->validate('red'));
		}
		
		public function testValueInFailsValueNotInAllowedList(): void {
			$rule = new ValueIn(['red', 'green', 'blue']);
			$this->assertFalse($rule->validate('purple'));
		}
		
		public function testValueInPassesEmptyString(): void {
			$rule = new ValueIn(['red', 'green']);
			$this->assertTrue($rule->validate(''));
		}
		
		public function testValueInPassesNull(): void {
			$rule = new ValueIn(['red', 'green']);
			$this->assertTrue($rule->validate(null));
		}
		
		public function testValueInPassesWhenListIsEmpty(): void {
			// No allowed values configured — rule is effectively disabled
			$rule = new ValueIn([]);
			$this->assertTrue($rule->validate('anything'));
		}
		
		public function testValueInSupportsIntegerValues(): void {
			$rule = new ValueIn([1, 2, 3]);
			$this->assertTrue($rule->validate(2));
			$this->assertFalse($rule->validate(5));
		}
		
		public function testValueInErrorMessageListsAllowedValues(): void {
			$rule = new ValueIn(['a', 'b']);
			$rule->validate('x');
			$error = $rule->getError();
			$this->assertStringContainsString("'a'", $error);
			$this->assertStringContainsString("'b'", $error);
		}
		
		// -------------------------------------------------------------------------
		// AtLeastOneOf
		// -------------------------------------------------------------------------
		
		public function testAtLeastOneOfPassesWhenOneConditionPasses(): void {
			$rule = new AtLeastOneOf([new Email(), new PhoneNumber()]);
			$this->assertTrue($rule->validate('user@example.com'));
		}
		
		public function testAtLeastOneOfPassesWhenAllConditionsPass(): void {
			$rule = new AtLeastOneOf([new NotBlank(), new NotBlank()]);
			$this->assertTrue($rule->validate('hello'));
		}
		
		public function testAtLeastOneOfFailsWhenNoConditionPasses(): void {
			$rule = new AtLeastOneOf([new Email(), new Email()]);
			$this->assertFalse($rule->validate('not-an-email'));
		}
		
		public function testAtLeastOneOfPassesWithEmptyConditionList(): void {
			// No conditions → counter stays 0 → returns false
			$rule = new AtLeastOneOf([]);
			$this->assertFalse($rule->validate('hello'));
		}
		
		public function testAtLeastOneOfReturnsDefaultErrorMessage(): void {
			$rule = new AtLeastOneOf([new Email()]);
			$this->assertNotEmpty($rule->getError());
		}
		
		public function testAtLeastOneOfReturnsCustomErrorMessage(): void {
			$rule = new AtLeastOneOf([new Email()], 'Provide a valid email or phone.');
			$this->assertSame('Provide a valid email or phone.', $rule->getError());
		}
		
		// -------------------------------------------------------------------------
		// Type
		// -------------------------------------------------------------------------
		
		public function testTypePassesStringValueForStringType(): void {
			$rule = new Type('string');
			$this->assertTrue($rule->validate('hello'));
		}
		
		public function testTypeFailsIntegerValueForStringType(): void {
			$rule = new Type('string');
			$this->assertFalse($rule->validate(42));
		}
		
		public function testTypePassesIntegerValueForIntType(): void {
			$rule = new Type('int');
			$this->assertTrue($rule->validate(42));
		}
		
		public function testTypePassesAlphaStringForAlphaType(): void {
			$rule = new Type('alpha');
			$this->assertTrue($rule->validate('Hello'));
		}
		
		public function testTypeFailsNumericStringForAlphaType(): void {
			$rule = new Type('alpha');
			$this->assertFalse($rule->validate('Hello123'));
		}
		
		public function testTypePassesDigitStringForDigitType(): void {
			$rule = new Type('digit');
			$this->assertTrue($rule->validate('12345'));
		}
		
		public function testTypeFailsNonDigitStringForDigitType(): void {
			$rule = new Type('digit');
			$this->assertFalse($rule->validate('123abc'));
		}
		
		public function testTypeThrowsOnUnsupportedType(): void {
			$this->expectException(\InvalidArgumentException::class);
			new Type('unsupported_type_xyz');
		}
		
		public function testTypeReturnsDefaultErrorMessage(): void {
			$rule = new Type('string');
			$this->assertNotEmpty($rule->getError());
		}
	}