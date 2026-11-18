<?php

	namespace Quellabs\CanvasAuthorization\Tests\Sculpt;

	use PHPUnit\Framework\TestCase;
	use Quellabs\CanvasAuthorization\SharedAuthStubsInstaller;
	use Quellabs\CanvasAuthorization\Sculpt\OrmAuthInstallCommand;
	use Quellabs\Contracts\Discovery\ProviderInterface;
	use Quellabs\Contracts\IO\ConsoleInput;
	use Quellabs\Contracts\IO\ConsoleOutput;

	/**
	 * Regression test for the shared/own stub merge in OrmAuthInstallCommand:
	 * every ORM package's install:auth-{orm} command relies on getStubs()
	 * merging in canvas-authorization's shared stubs, and on resolveStubPath()
	 * falling back to canvas-authorization's own stubs/ directory for entries
	 * the ORM package doesn't provide itself.
	 */
	class OrmAuthInstallCommandTest extends TestCase {

		private function makeCommand(): OrmAuthInstallCommand {
			return new class (
				$this->createMock(ConsoleInput::class),
				$this->createMock(ConsoleOutput::class),
				$this->createMock(ProviderInterface::class),
			) extends OrmAuthInstallCommand {
				public function getSignature(): string {
					return 'install:auth-fixture';
				}

				public function getDescription(): string {
					return '';
				}

				protected function getOrmStubs(): array {
					return ['Entities/UserEntity.php' => 'src/Entities/UserEntity.php'];
				}

				protected function showNextSteps(): void {
				}
			};
		}

		public function testGetStubsMergesTheSharedStubsWithTheOrmPackagesOwnStubs(): void {
			$command = $this->makeCommand();
			$method = new \ReflectionMethod($command, 'getStubs');
			$stubs = $method->invoke($command);

			$this->assertArrayHasKey('Entities/UserEntity.php', $stubs);
			$this->assertArrayHasKey('Errors/AuthErrorHandler.php', $stubs);
			$this->assertSame('src/Errors/AuthErrorHandler.php', $stubs['Errors/AuthErrorHandler.php']);
		}

		public function testResolveStubPathFallsBackToTheSharedStubRootForSharedOnlyEntries(): void {
			$command = $this->makeCommand();
			$method = new \ReflectionMethod($command, 'resolveStubPath');

			$this->assertSame(
				SharedAuthStubsInstaller::getStubRoot() . 'Errors/AuthErrorHandler.php',
				$method->invoke($command, 'Errors/AuthErrorHandler.php'),
			);
		}
	}
