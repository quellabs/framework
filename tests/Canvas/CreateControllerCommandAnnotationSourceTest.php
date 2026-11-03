<?php

	namespace Quellabs\Canvas\Tests;

	use PHPUnit\Framework\TestCase;
	use Quellabs\AnnotationReader\Configuration as AnnotationReaderConfiguration;
	use Quellabs\Canvas\Sculpt\CreateControllerCommand;
	use Quellabs\Contracts\Discovery\ProviderInterface;
	use Quellabs\Contracts\IO\ConsoleInput;
	use Quellabs\Contracts\IO\ConsoleOutput;

	/**
	 * make:controller must generate a @Route docblock annotation by default
	 * and a native #[Route] attribute when the app's "annotation_source"
	 * setting is Configuration::SOURCE_ATTRIBUTES.
	 */
	class CreateControllerCommandAnnotationSourceTest extends TestCase {

		/**
		 * @param string $annotationSource
		 * @return CreateControllerCommand
		 */
		private function makeCommand(string $annotationSource): CreateControllerCommand {
			$provider = $this->createStub(ProviderInterface::class);
			$provider->method('getConfig')->willReturn(['annotation_source' => $annotationSource]);

			return new CreateControllerCommand(
				$this->createStub(ConsoleInput::class),
				$this->createStub(ConsoleOutput::class),
				$provider
			);
		}

		/**
		 * @param CreateControllerCommand $command
		 * @param string $controllerName
		 * @return string
		 */
		private function createController(CreateControllerCommand $command, string $controllerName): string {
			$method = new \ReflectionMethod(CreateControllerCommand::class, 'createController');
			$method->setAccessible(true);
			return $method->invoke($command, $controllerName);
		}

		/**
		 * @return void
		 */
		public function testGeneratesDocblockRouteAnnotationByDefault(): void {
			$command = $this->makeCommand(AnnotationReaderConfiguration::SOURCE_DOCBLOCKS);

			$code = $this->createController($command, 'User');

			$this->assertStringContainsString('@Route("/user", methods={"GET"})', $code);
			$this->assertStringContainsString('class UserController extends BaseController', $code);
			$this->assertStringNotContainsString('#[Route(', $code);
		}

		/**
		 * @return void
		 */
		public function testGeneratesNativeRouteAttributeWhenConfigured(): void {
			$command = $this->makeCommand(AnnotationReaderConfiguration::SOURCE_ATTRIBUTES);

			$code = $this->createController($command, 'User');

			$this->assertStringContainsString('#[Route("/user", methods: ["GET"])]', $code);
			$this->assertStringContainsString('class UserController extends BaseController', $code);
			$this->assertStringNotContainsString('@Route(', $code);
		}

		/**
		 * A missing "annotation_source" config key falls back to docblocks,
		 * same as the AnnotationReader default.
		 * @return void
		 */
		public function testFallsBackToDocblocksWhenAnnotationSourceIsNotConfigured(): void {
			$provider = $this->createStub(ProviderInterface::class);
			$provider->method('getConfig')->willReturn([]);

			$command = new CreateControllerCommand(
				$this->createStub(ConsoleInput::class),
				$this->createStub(ConsoleOutput::class),
				$provider
			);

			$code = $this->createController($command, 'User');

			$this->assertStringContainsString('@Route("/user", methods={"GET"})', $code);
			$this->assertStringNotContainsString('#[Route(', $code);
		}
	}
