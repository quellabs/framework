<?php
	
	namespace Quellabs\Canvas\Tests\Misc;
	
	use PHPUnit\Framework\TestCase;
	use Quellabs\Canvas\Legacy\LegacyExitException;
	use Quellabs\Canvas\Scheduler\JobResult;
	use Quellabs\Canvas\Validation\Rules\Zipcode;
	
	/**
	 * Unit tests for small pure-logic classes that don't fit a larger grouping:
	 * LegacyExitException, JobResult, and Zipcode.
	 */
	class MiscTest extends TestCase {
		
		// =========================================================================
		// LegacyExitException
		// =========================================================================
		
		public function testLegacyExitExceptionExtendsException(): void {
			$e = new LegacyExitException();
			$this->assertInstanceOf(\Exception::class, $e);
		}
		
		public function testLegacyExitExceptionDefaultExitCodeIsZero(): void {
			$e = new LegacyExitException();
			$this->assertSame(0, $e->getExitCode());
		}
		
		public function testLegacyExitExceptionStoresExitCode(): void {
			$e = new LegacyExitException(1);
			$this->assertSame(1, $e->getExitCode());
		}
		
		public function testLegacyExitExceptionDefaultMessage(): void {
			$e = new LegacyExitException();
			$this->assertNotEmpty($e->getMessage());
		}
		
		public function testLegacyExitExceptionCustomMessage(): void {
			$e = new LegacyExitException(0, 'Custom exit message');
			$this->assertSame('Custom exit message', $e->getMessage());
		}
		
		// =========================================================================
		// JobResult
		// =========================================================================
		
		private function makeJobResult(bool $success, int $duration = 0, ?\Exception $ex = null): JobResult {
			// JobResult requires a JobInterface — use a minimal anonymous implementation
			$job = new class implements \Quellabs\Contracts\Scheduler\JobInterface {
				public function handle(): void {}
				public function getName(): string { return 'test-job'; }
				public function getDescription(): string { return ''; }
				public function enabled(): bool { return true; }
				public function getSchedule(): string { return '* * * * *'; }
				public function getTimeout(): int { return 60; }
				public function getMaxRetries(): int { return 0; }
				public function onTimeout(\Quellabs\Contracts\Scheduler\TaskTimeoutException $e): void {}
				public function onFailure(\Exception $e): void {}
			};
			
			return new JobResult($job, $success, $duration, $ex);
		}
		
		public function testJobResultIsSuccessReturnsTrueOnSuccess(): void {
			$result = $this->makeJobResult(true);
			$this->assertTrue($result->isSuccess());
		}
		
		public function testJobResultIsSuccessReturnsFalseOnFailure(): void {
			$result = $this->makeJobResult(false);
			$this->assertFalse($result->isSuccess());
		}
		
		public function testJobResultGetExceptionReturnsNullOnSuccess(): void {
			$result = $this->makeJobResult(true);
			$this->assertNull($result->getException());
		}
		
		public function testJobResultGetExceptionReturnsException(): void {
			$ex     = new \RuntimeException('something broke');
			$result = $this->makeJobResult(false, 0, $ex);
			$this->assertSame($ex, $result->getException());
		}
		
		public function testJobResultGetDurationReturnsZeroDefault(): void {
			$result = $this->makeJobResult(true);
			$this->assertSame(0, $result->getDuration());
		}
		
		public function testJobResultGetDurationReturnsProvidedValue(): void {
			$result = $this->makeJobResult(true, 150);
			$this->assertSame(150, $result->getDuration());
		}
		
		// =========================================================================
		// Zipcode
		// =========================================================================
		
		public function testZipcodePassesValidDutchPostcode(): void {
			$rule = new Zipcode('NL');
			$this->assertTrue($rule->validate('1234 AB'));
		}
		
		public function testZipcodeFailsInvalidDutchPostcode(): void {
			$rule = new Zipcode('NL');
			$this->assertFalse($rule->validate('ABCD EF'));
		}
		
		public function testZipcodePassesValidGermanPostcode(): void {
			$rule = new Zipcode('DE');
			$this->assertTrue($rule->validate('10115'));
		}
		
		public function testZipcodeFailsInvalidGermanPostcode(): void {
			$rule = new Zipcode('DE');
			$this->assertFalse($rule->validate('ABC'));
		}
		
		public function testZipcodePassesValidUkPostcode(): void {
			$rule = new Zipcode('GB');
			$this->assertTrue($rule->validate('SW1A 1AA'));
		}
		
		public function testZipcodePassesValidUsZipCode(): void {
			$rule = new Zipcode('US');
			$this->assertTrue($rule->validate('90210'));
		}
		
		public function testZipcodePassesValidUsZipCodeWithDash(): void {
			$rule = new Zipcode('US');
			$this->assertTrue($rule->validate('90210-1234'));
		}
		
		public function testZipcodePassesEmptyString(): void {
			// Empty is always allowed — use NotBlank separately for mandatory fields
			$rule = new Zipcode('NL');
			$this->assertTrue($rule->validate(''));
		}
		
		public function testZipcodePassesNull(): void {
			$rule = new Zipcode('NL');
			$this->assertTrue($rule->validate(null));
		}
		
		public function testZipcodeFailsNonStringValue(): void {
			$rule = new Zipcode('NL');
			$this->assertFalse($rule->validate(1234));
		}
		
		public function testZipcodeReturnsDefaultErrorMessage(): void {
			$rule = new Zipcode('NL');
			$this->assertNotEmpty($rule->getError());
		}
		
		public function testZipcodeReturnsCustomErrorMessage(): void {
			$rule = new Zipcode('NL', 'Enter a valid Dutch postcode.');
			$this->assertSame('Enter a valid Dutch postcode.', $rule->getError());
		}
		
		public function testZipcodeDefaultCountryIsNl(): void {
			// Default constructor should behave the same as explicit NL
			$rule = new Zipcode();
			$this->assertTrue($rule->validate('1234 AB'));
		}
	}