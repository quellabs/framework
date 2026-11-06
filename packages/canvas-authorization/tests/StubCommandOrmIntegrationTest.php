<?php

	namespace Quellabs\CanvasAuthorization\Tests;

	use PHPUnit\Framework\TestCase;
	use Quellabs\Contracts\Discovery\ProviderInterface;
	use Quellabs\Contracts\IO\ConsoleInput;
	use Quellabs\Contracts\IO\ConsoleOutput;
	use Quellabs\CanvasAuthorization\MakeAuthAspectCommand;
	use Quellabs\CanvasAuthorization\MakeAuthCommand;

	/**
	 * Regression test for the OrmEngineResolver move to quellabs/support:
	 * getStubs() on both commands calls OrmEngineResolver::resolve(), which
	 * only fatals at runtime (an undefined-class error, not a parse error) if
	 * the use-import is missing — php -l and the rest of this suite can't
	 * catch that, since nothing else exercises these commands.
	 */
	class StubCommandOrmIntegrationTest extends TestCase {

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

		/**
		 * @return void
		 */
		public function testMakeAuthCommandGetStubsResolvesAnOrmVariantForEveryOrmSpecificStub(): void {
			$stubs = $this->callGetStubs($this->makeCommand(MakeAuthCommand::class));

			$this->assertArrayHasKey('src/Entities/UserEntity.php', array_flip($stubs));
			$this->assertArrayHasKey('src/Controllers/AuthenticationController.php', array_flip($stubs));

			foreach (['UserEntity.php', 'PasswordResetTokenEntity.php'] as $file) {
				$stubPath = array_search("src/Entities/{$file}", $stubs, true);
				$this->assertMatchesRegularExpression('#^Entities/(doctrine|eloquent|objectquel)/' . preg_quote($file, '#') . '$#', $stubPath);
			}
		}

		/**
		 * @return void
		 */
		public function testMakeAuthAspectCommandGetStubsResolvesAnOrmVariant(): void {
			$stubs = $this->callGetStubs($this->makeCommand(MakeAuthAspectCommand::class));

			$stubPath = array_search('src/Aspects/AccountEligibilityAspect.php', $stubs, true);
			$this->assertMatchesRegularExpression('#^Aspects/(doctrine|eloquent|objectquel)/AccountEligibilityAspect\.php$#', $stubPath);
		}
	}
