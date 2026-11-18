<?php

	namespace Quellabs\CanvasAuthorization\ObjectQuel\Tests\Sculpt;

	use PHPUnit\Framework\TestCase;
	use Quellabs\CanvasAuthorization\ObjectQuel\Sculpt\InstallAuthCommand;
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

		public function testSignatureIsInstallAuthObjectquel(): void {
			$this->assertSame('install:auth-objectquel', $this->makeCommand(InstallAuthCommand::class)->getSignature());
		}

		public function testGetStubsIncludesItsOwnOrmSpecificStubsButNoAspect(): void {
			$stubs = $this->callGetStubs($this->makeCommand(InstallAuthCommand::class));

			$this->assertSame('src/Entities/UserEntity.php', $stubs['Entities/UserEntity.php']);
			$this->assertSame('src/Controllers/AuthenticationController.php', $stubs['Controllers/AuthenticationController.php']);
			$this->assertArrayNotHasKey('Aspects/AccountEligibilityAspect.php', $stubs);
		}

		public function testGetStubsAlsoIncludesTheSharedCanvasAuthorizationStubs(): void {
			$stubs = $this->callGetStubs($this->makeCommand(InstallAuthCommand::class));

			$this->assertSame('src/Errors/AuthErrorHandler.php', $stubs['Errors/AuthErrorHandler.php']);
			$this->assertSame('src/Validation/LoginFormValidator.php', $stubs['Validation/LoginFormValidator.php']);
		}
	}
