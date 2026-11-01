<?php

	namespace Quellabs\CanvasDoctrine\Tests;
	require_once __DIR__ . '/Fixtures/DoctrineTestEntity.php';

	use Doctrine\ORM\EntityManagerInterface;
	use PHPUnit\Framework\TestCase;
	use Quellabs\AnnotationReader\AnnotationReader;
	use Quellabs\AnnotationReader\Collection\AnnotationCollection;
	use Quellabs\CanvasDoctrine\Annotations\ResolveEntity;
	use Quellabs\CanvasDoctrine\EntityBindingServiceProvider;
	use Quellabs\Contracts\Context\MethodContextInterface;
	use Quellabs\Contracts\DependencyInjection\ContainerInterface;
	use Quellabs\Contracts\DependencyInjection\ResolutionNotFoundException;

	/**
	 * Covers route binding through the Doctrine manager.
	 */
	class EntityBindingServiceProviderTest extends TestCase {
		/**
		 * Resolves an annotated route key through EntityManager::find.
		 * @return void
		 */
		public function testAnnotatedBinding(): void {
			$entity = new DoctrineTestEntity(4);
			$manager = $this->createMock(EntityManagerInterface::class);
			$manager->expects($this->once())->method('find')->with(DoctrineTestEntity::class, '4')->willReturn($entity);
			$provider = $this->makeProvider($manager, [new ResolveEntity('post', 'postId')]);
			$this->assertSame($entity, $provider->createInstance(DoctrineTestEntity::class, [], [], $this->context(['postId' => '4'])));
		}

		/**
		 * Reports a valid lookup with no matching row.
		 * @return void
		 */
		public function testMissingEntity(): void {
			$manager = $this->createMock(EntityManagerInterface::class);
			$manager->method('find')->willReturn(null);
			$provider = $this->makeProvider($manager, []);
			$this->expectException(ResolutionNotFoundException::class);
			$provider->createInstance(DoctrineTestEntity::class, [], [], $this->context(['post' => 99]));
		}

		/**
		 * Rejects a missing route key before querying.
		 * @return void
		 */
		public function testMissingRouteKey(): void {
			$manager = $this->createMock(EntityManagerInterface::class);
			$manager->expects($this->never())->method('find');
			$provider = $this->makeProvider($manager, []);
			$this->expectException(\InvalidArgumentException::class);
			$provider->createInstance(DoctrineTestEntity::class, [], [], $this->context([]));
		}

		/**
		 * Creates the provider with its two lazy services.
		 * @param EntityManagerInterface $manager
		 * @param array<int, ResolveEntity> $annotations
		 * @return EntityBindingServiceProvider
		 */
		private function makeProvider(EntityManagerInterface $manager, array $annotations): EntityBindingServiceProvider {
			$reader = $this->createMock(AnnotationReader::class);
			$reader->method('getMethodAnnotations')->willReturn(new AnnotationCollection($annotations));
			$container = $this->createMock(ContainerInterface::class);
			$container->method('get')->willReturnCallback(fn (string $className) => $className === AnnotationReader::class ? $reader : $manager);
			return new EntityBindingServiceProvider($container);
		}

		/**
		 * Creates a controller method context.
		 * @param array<string, mixed> $arguments
		 * @return MethodContextInterface
		 */
		private function context(array $arguments): MethodContextInterface {
			$context = $this->createMock(MethodContextInterface::class);
			$context->method('getClassName')->willReturn(self::class);
			$context->method('getMethodName')->willReturn('testAnnotatedBinding');
			$context->method('getParameterName')->willReturn('post');
			$context->method('getArguments')->willReturn($arguments);
			return $context;
		}
	}
