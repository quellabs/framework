<?php

declare(strict_types=1);

	namespace Quellabs\Canvas\Tests;

	use Monolog\Handler\ErrorLogHandler;
	use Monolog\Handler\RotatingFileHandler;
	use Monolog\Handler\StreamHandler;
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
	 * Fixture whose @LoggerContext overrides handler, logfile, and channel.
	 */
	class LoggerInterfaceProviderOverrideFixture {
		/**
		 * @LoggerContext(handler="rotating", logfile="payments.log", channel="payments")
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
	 * Verifies LoggerInterfaceProvider's handler/logfile/channel resolution:
	 * @WithContext's literal context tag (via $metadata) still selects the
	 * handler, while @LoggerContext — read directly off the method — supplies
	 * logfile/channel overrides and may itself override the handler.
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

		public function testLoggerContextOverridesHandlerLogfileAndChannel(): void {
			$provider = $this->makeProvider();
			$methodContext = new LoggerInterfaceProviderFakeMethodContext(LoggerInterfaceProviderOverrideFixture::class, 'index');

			$logger = $provider->createInstance(LoggerInterface::class, [], [], $methodContext);

			$this->assertSame('payments', $logger->getName());
			$this->assertInstanceOf(RotatingFileHandler::class, $logger->getHandlers()[0]);
		}

		public function testLoggerContextHandlerTakesPrecedenceOverWithContextTag(): void {
			$provider = $this->makeProvider();
			$methodContext = new LoggerInterfaceProviderFakeMethodContext(LoggerInterfaceProviderOverrideFixture::class, 'index');

			// WithContext says 'stream', but @LoggerContext's handler="rotating" wins
			$logger = $provider->createInstance(LoggerInterface::class, [], ['context' => 'stream'], $methodContext);

			$this->assertInstanceOf(RotatingFileHandler::class, $logger->getHandlers()[0]);
		}

		public function testLoggerContextChannelOnlyLeavesHandlerToWithContextTag(): void {
			$provider = $this->makeProvider();
			$methodContext = new LoggerInterfaceProviderFakeMethodContext(LoggerInterfaceProviderChannelOnlyFixture::class, 'index');

			$logger = $provider->createInstance(LoggerInterface::class, [], ['context' => 'rotating'], $methodContext);

			$this->assertSame('admin', $logger->getName());
			$this->assertInstanceOf(RotatingFileHandler::class, $logger->getHandlers()[0]);
		}

		public function testNullMethodContextLeavesLoggerContextUnresolved(): void {
			$provider = $this->makeProvider();

			$logger = $provider->createInstance(LoggerInterface::class, [], ['context' => 'stderr'], null);

			$this->assertSame('app', $logger->getName());
			$this->assertInstanceOf(ErrorLogHandler::class, $logger->getHandlers()[0]);
		}
	}
