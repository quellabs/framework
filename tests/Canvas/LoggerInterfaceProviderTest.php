<?php

declare(strict_types=1);

	namespace Quellabs\Canvas\Tests;

	use Monolog\Handler\ErrorLogHandler;
	use Monolog\Handler\RotatingFileHandler;
	use Monolog\Handler\StreamHandler;
	use Monolog\Logger;
	use PHPUnit\Framework\TestCase;
	use Psr\Log\LoggerInterface;
	use Quellabs\AnnotationReader\AnnotationReader;
	use Quellabs\AnnotationReader\Configuration;
	use Quellabs\Canvas\Annotations\LoggerContext;
	use Quellabs\Canvas\Logger\LoggerInterfaceProvider;
	use Quellabs\Contracts\Context\MethodContextInterface;

	/**
	 * Fake MethodContext pointing createInstance() at one of the fixture
	 * classes/methods below, so LoggerInterfaceProvider reads @LoggerContext
	 * from a real, annotation-reader-parsed docblock.
	 */
	class LoggerInterfaceProviderFakeMethodContext implements MethodContextInterface {
		private string $className;
		private string $methodName;

		public function __construct(string $className, string $methodName) {
			$this->className = $className;
			$this->methodName = $methodName;
		}

		public function getClassName(): string {
			return $this->className;
		}

		public function getMethodName(): string {
			return $this->methodName;
		}

		public function getArguments(): array {
			return [];
		}

		public function setParameterName(?string $name): void {
		}

		public function getParameterName(): ?string {
			return null;
		}
	}

	/**
	 * Fixture with no @LoggerContext annotation at all.
	 */
	class LoggerInterfaceProviderPlainFixture {
		public function index(): void {
		}
	}

	/**
	 * Fixture whose @LoggerContext overrides logfile and channel. Handler
	 * selection is never affected by @LoggerContext — only @WithContext can do that.
	 */
	class LoggerInterfaceProviderOverrideFixture {
		/**
		 * @LoggerContext(logfile="payments.log", channel="payments")
		 */
		public function index(): void {
		}
	}

	/**
	 * Fixture whose @LoggerContext only overrides the channel, leaving handler
	 * selection to @WithContext's literal context tag (passed as $metadata here).
	 */
	class LoggerInterfaceProviderChannelOnlyFixture {
		/**
		 * @LoggerContext(channel="admin")
		 */
		public function index(): void {
		}
	}

	/**
	 * Fixture carrying one @LoggerContext per handler @WithContext can resolve
	 * to, plus an untagged default used when the resolved handler matches neither.
	 */
	class LoggerInterfaceProviderMultiContextFixture {
		/**
		 * @LoggerContext(context="rotating", logfile="payments.log", channel="payments")
		 * @LoggerContext(context="stderr", channel="alerts")
		 * @LoggerContext(channel="admin")
		 */
		public function index(): void {
		}
	}

	/**
	 * Verifies LoggerInterfaceProvider's handler/logfile/channel resolution:
	 * @WithContext's literal context tag (via $metadata) is the sole handler
	 * selector, while @LoggerContext — read directly off the method — only
	 * supplies logfile/channel overrides.
	 */
	class LoggerInterfaceProviderTest extends TestCase {

		private function makeProvider(): LoggerInterfaceProvider {
			$configuration = new Configuration();
			$configuration->setSource(Configuration::SOURCE_DOCBLOCKS);
			return new LoggerInterfaceProvider('/tmp/default.log', new AnnotationReader($configuration));
		}

		public function testNoAnnotationOrMetadataDefaultsToStreamAndAppChannel(): void {
			$provider = $this->makeProvider();
			$methodContext = new LoggerInterfaceProviderFakeMethodContext(LoggerInterfaceProviderPlainFixture::class, 'index');

			$logger = $provider->createInstance(LoggerInterface::class, [], [], $methodContext);

			$this->assertSame('app', $logger->getName());
			$this->assertInstanceOf(StreamHandler::class, $logger->getHandlers()[0]);
		}

		public function testWithContextLiteralTagStillSelectsHandlerWithoutLoggerContext(): void {
			$provider = $this->makeProvider();
			$methodContext = new LoggerInterfaceProviderFakeMethodContext(LoggerInterfaceProviderPlainFixture::class, 'index');

			$logger = $provider->createInstance(LoggerInterface::class, [], ['context' => 'stderr'], $methodContext);

			$this->assertInstanceOf(ErrorLogHandler::class, $logger->getHandlers()[0]);
		}

		public function testLoggerContextOverridesChannelButNotHandler(): void {
			$provider = $this->makeProvider();
			$methodContext = new LoggerInterfaceProviderFakeMethodContext(LoggerInterfaceProviderOverrideFixture::class, 'index');

			$logger = $provider->createInstance(LoggerInterface::class, [], [], $methodContext);

			$this->assertSame('payments', $logger->getName());
			$this->assertInstanceOf(StreamHandler::class, $logger->getHandlers()[0]);
		}

		public function testWithContextTagSelectsHandlerEvenWithLoggerContextPresent(): void {
			$provider = $this->makeProvider();
			$methodContext = new LoggerInterfaceProviderFakeMethodContext(LoggerInterfaceProviderOverrideFixture::class, 'index');

			$logger = $provider->createInstance(LoggerInterface::class, [], ['context' => 'rotating'], $methodContext);

			$this->assertInstanceOf(RotatingFileHandler::class, $logger->getHandlers()[0]);
		}

		public function testLoggerContextChannelOnlyLeavesHandlerToWithContextTag(): void {
			$provider = $this->makeProvider();
			$methodContext = new LoggerInterfaceProviderFakeMethodContext(LoggerInterfaceProviderChannelOnlyFixture::class, 'index');

			$logger = $provider->createInstance(LoggerInterface::class, [], ['context' => 'rotating'], $methodContext);

			$this->assertSame('admin', $logger->getName());
			$this->assertInstanceOf(RotatingFileHandler::class, $logger->getHandlers()[0]);
		}

		public function testMultiContextFixtureUsesTheLoggerContextTaggedForTheResolvedHandler(): void {
			$provider = $this->makeProvider();
			$methodContext = new LoggerInterfaceProviderFakeMethodContext(LoggerInterfaceProviderMultiContextFixture::class, 'index');

			$logger = $provider->createInstance(LoggerInterface::class, [], ['context' => 'rotating'], $methodContext);

			$this->assertSame('payments', $logger->getName());
			$this->assertInstanceOf(RotatingFileHandler::class, $logger->getHandlers()[0]);
		}

		public function testMultiContextFixtureSwitchesLoggerContextWhenResolvedHandlerChanges(): void {
			$provider = $this->makeProvider();
			$methodContext = new LoggerInterfaceProviderFakeMethodContext(LoggerInterfaceProviderMultiContextFixture::class, 'index');

			$logger = $provider->createInstance(LoggerInterface::class, [], ['context' => 'stderr'], $methodContext);

			$this->assertSame('alerts', $logger->getName());
			$this->assertInstanceOf(ErrorLogHandler::class, $logger->getHandlers()[0]);
		}

		public function testMultiContextFixtureFallsBackToUntaggedDefaultWhenNoTagMatches(): void {
			$provider = $this->makeProvider();
			$methodContext = new LoggerInterfaceProviderFakeMethodContext(LoggerInterfaceProviderMultiContextFixture::class, 'index');

			$logger = $provider->createInstance(LoggerInterface::class, [], ['context' => 'stream'], $methodContext);

			$this->assertSame('admin', $logger->getName());
			$this->assertInstanceOf(StreamHandler::class, $logger->getHandlers()[0]);
		}

		public function testNullMethodContextLeavesLoggerContextUnresolved(): void {
			$provider = $this->makeProvider();

			$logger = $provider->createInstance(LoggerInterface::class, [], ['context' => 'stderr'], null);

			$this->assertSame('app', $logger->getName());
			$this->assertInstanceOf(ErrorLogHandler::class, $logger->getHandlers()[0]);
		}

		/**
		 * Log file paths the provider must use as given, and a relative one it puts below storage/logs.
		 * @return array<string, array{string, bool}> Path => whether it is used as given
		 */
		public static function logfilePathProvider(): array {
			return [
				'unix absolute'              => ['/var/log/app.log', true],
				'windows drive, backslashes' => ['C:\\logs\\app.log', true],
				'windows drive, slashes'     => ['C:/logs/app.log', true],
				'stream'                     => ['php://stderr', true],
				'relative'                   => ['app.log', false],
			];
		}

		/**
		 * An absolute path (Unix or Windows) or a stream is used as given; a
		 * relative name is placed below the project's storage/logs.
		 * @dataProvider logfilePathProvider
		 * @param string $path
		 * @param bool $usedAsGiven
		 * @return void
		 */
		public function testResolvesTheLogfilePath(string $path, bool $usedAsGiven): void {
			$configuration = new Configuration();
			$configuration->setSource(Configuration::SOURCE_DOCBLOCKS);
			$provider = new LoggerInterfaceProvider($path, new AnnotationReader($configuration));

			$logger = $provider->createInstance(LoggerInterface::class, [], [], null);
			$this->assertInstanceOf(Logger::class, $logger);
			$handler = $logger->getHandlers()[0];
			$this->assertInstanceOf(StreamHandler::class, $handler);

			if ($usedAsGiven) {
				$this->assertSame($path, $handler->getUrl());
			} else {
				$this->assertStringEndsWith('/storage/logs/' . $path, (string)$handler->getUrl());
			}
		}
	}
