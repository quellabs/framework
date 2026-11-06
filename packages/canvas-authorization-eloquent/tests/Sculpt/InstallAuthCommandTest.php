<?php

	namespace Quellabs\CanvasAuthorization\Eloquent\Tests\Sculpt;

	use PHPUnit\Framework\TestCase;
	use Quellabs\CanvasAuthorization\Eloquent\Sculpt\InstallAuthCommand;
	use Quellabs\CanvasAuthorization\Eloquent\Sculpt\MakeAuthAspectCommand;
	use Quellabs\Contracts\Discovery\ProviderInterface;
	use Quellabs\Contracts\IO\ConsoleInput;
	use Quellabs\Contracts\IO\ConsoleOutput;

	class InstallAuthCommandTest extends TestCase {

		/**
		 * @template T of object
		 * @param class-string<T> $class
		 * @return T
		 */
		private function makeCommand(string $class): object {
			return new $class(
				$this->createMock(ConsoleInput::class),
				$this->createMock(ConsoleOutput::class),
				$this->createMock(ProviderInterface::class),
			);
		}

		/**
		 * @param object $command
		 * @return array<string, string>
		 */
		private function callGetStubs(object $command): array {
			$method = new \ReflectionMethod($command, 'getStubs');
			return $method->invoke($command);
		}

		public function testSignatureIsInstallAuthEloquent(): void {
			$this->assertSame('install:auth-eloquent', $this->makeCommand(InstallAuthCommand::class)->getSignature());
		}

		public function testGetStubsIncludesItsOwnOrmSpecificStubs(): void {
			$stubs = $this->callGetStubs($this->makeCommand(InstallAuthCommand::class));

			$this->assertSame('src/Entities/UserEntity.php', $stubs['Entities/UserEntity.php']);
			$this->assertSame('src/Controllers/AuthenticationController.php', $stubs['Controllers/AuthenticationController.php']);
			$this->assertSame('src/Aspects/AccountEligibilityAspect.php', $stubs['Aspects/AccountEligibilityAspect.php']);
		}

		public function testGetStubsAlsoIncludesTheSharedCanvasAuthorizationStubs(): void {
			$stubs = $this->callGetStubs($this->makeCommand(InstallAuthCommand::class));

			$this->assertSame('src/Errors/AuthErrorHandler.php', $stubs['Errors/AuthErrorHandler.php']);
			$this->assertSame('src/Validation/LoginFormValidator.php', $stubs['Validation/LoginFormValidator.php']);
		}

		public function testMakeAuthAspectCommandGetStubs(): void {
			$stubs = $this->callGetStubs($this->makeCommand(MakeAuthAspectCommand::class));

			$this->assertSame(['Aspects/AccountEligibilityAspect.php' => 'src/Aspects/AccountEligibilityAspect.php'], $stubs);
		}
	}
