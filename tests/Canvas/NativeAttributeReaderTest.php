<?php

declare(strict_types=1);

namespace Quellabs\Canvas\Tests\AnnotationReader;

use PHPUnit\Framework\TestCase;
use Quellabs\AnnotationReader\AnnotationInterface;
use Quellabs\AnnotationReader\AnnotationReader;
use Quellabs\AnnotationReader\Configuration;
use Quellabs\AnnotationReader\Exception\AnnotationReaderException;
use Quellabs\AnnotationReader\Annotation\ReflectsAnnotationParameters;
use Quellabs\Canvas\Annotations\Route;
use Quellabs\Canvas\Annotations\RoutePrefix;
use Quellabs\Canvas\Annotations\ListenTo;
use Quellabs\Canvas\Annotations\InterceptWith;
use Quellabs\Canvas\Annotations\CacheContext;
use Quellabs\Canvas\Annotations\WithContext;
use Quellabs\Canvas\AOP\AspectResolver;
use Quellabs\Canvas\Kernel;
use Quellabs\Canvas\Routing\Components\ControllersDiscovery;
use Quellabs\Canvas\Routing\Components\RouteDiscovery;
use Quellabs\Canvas\Routing\Components\RoutePatternCompiler;
use Quellabs\Canvas\Routing\Components\RouteSegmentAnalyzer;
use Quellabs\Canvas\Signals\SignalConnector;
use Quellabs\ObjectQuel\Annotations\Orm\Column;
use Quellabs\ObjectQuel\Annotations\Orm\Table;
use Quellabs\ObjectQuel\Annotations\Validation\Length;
use Quellabs\AnnotationReader\AnnotationTest\AnotherTest;
use Quellabs\AnnotationReader\AnnotationTest\BaseService;
use Quellabs\AnnotationReader\AnnotationTest\CacheableTest;
use Quellabs\AnnotationReader\AnnotationTest\Test as FixtureTest;
use Quellabs\AnnotationReader\AnnotationTest\UserService;

#[\Attribute(\Attribute::TARGET_CLASS | \Attribute::TARGET_METHOD | \Attribute::TARGET_PROPERTY | \Attribute::IS_REPEATABLE)]
class CustomMarker implements AnnotationInterface {
    use ReflectsAnnotationParameters;
    private string $value;
    private int $priority;

    /**
     * @param string $value
     * @param int $priority
     * @return void
     */
    public function __construct(string $value, int $priority = 0) {
        $this->value = $value;
        $this->priority = $priority;
    }
}

#[\Attribute(\Attribute::TARGET_ALL)]
class UnrelatedMarker {}

class LegacyMarker implements AnnotationInterface {
    private array $parameters;

    /**
     * @param array<string, mixed> $parameters
     * @return void
     */
    public function __construct(array $parameters) {
        $this->parameters = $parameters;
    }

    /**
     * @return array<string, mixed>
     */
    public function getParameters(): array {
        return $this->parameters;
    }
}

/**
 * @RoutePrefix("doc")
 * @LegacyMarker(value="legacy")
 */
#[CustomMarker('class', priority: 2)]
#[UnrelatedMarker]
class MixedMetadataFixture {
    /**
     * @Route("/doc")
     */
    #[Route('/native', methods: ['POST'])]
    #[Route('/again')]
    #[CustomMarker('method')]
    public function action(): void {}

    /**
     * @Column(name="doc", type="string")
     */
    #[Column(name: 'native', type: 'string', limit: 25)]
    #[Length(property: 'name', min: 2)]
    #[CustomMarker('property')]
    public string $name = '';
}

class MixedMetadataChild extends MixedMetadataFixture {}

#[Route('/invalid-target')]
class InvalidTargetFixture {}

class InvalidArgumentsFixture {
    #[Route('/invalid', methods: 'GET')]
    public function action(): void {}
}

#[Table(name: 'attribute_fixture')]
class ObjectQuelAttributeFixture {
    #[Column(name: 'id', type: 'integer', primary_key: true)]
    public int $id = 0;
}

#[RoutePrefix('native')]
class NativeCanvasController {
    #[Route('/ping', methods: ['GET'])]
    #[ListenTo('demo.ping', priority: 3)]
    public function ping(): void {}
}

class DocblockOnlyAspectFixture {
    /**
     * @InterceptWith("stdClass", ttl=30)
     * @CacheContext(namespace="users", driver="redis")
     * @WithContext(parameter="engine", context="blade")
     */
    public function run(): void {}
}

class NativeAttributeAspectFixture {
    #[InterceptWith('stdClass', ttl: 30)]
    #[CacheContext(namespace: 'users', driver: 'redis')]
    #[WithContext('engine', context: 'blade')]
    public function run(): void {}
}

#[\Attribute(\Attribute::TARGET_CLASS)]
class NestedMarker implements AnnotationInterface {
    use ReflectsAnnotationParameters;
    private AnnotationInterface $value;

    /**
     * @param AnnotationInterface $value
     * @return void
     */
    public function __construct(AnnotationInterface $value) {
        $this->value = $value;
    }
}

#[NestedMarker(new CustomMarker('nested'))]
class NestedValueFixture {}

/**
 * @NestedMarker(@CustomMarker("nested"))
 */
class DocblockNestedValueFixture {}

class NativeAttributeReaderTest extends TestCase {
    private string $cacheDirectory;

    /**
     * @return void
     */
    protected function setUp(): void {
        $this->cacheDirectory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'annotation-reader-' . bin2hex(random_bytes(8));
        mkdir($this->cacheDirectory);
    }

    /**
     * @return void
     */
    protected function tearDown(): void {
        foreach (glob($this->cacheDirectory . DIRECTORY_SEPARATOR . '*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->cacheDirectory);
    }

    /**
     * @param string $source
     * @param bool $cache
     * @param bool $debug
     * @return AnnotationReader
     */
    private function reader(string $source, bool $cache = false, bool $debug = false): AnnotationReader {
        $configuration = new Configuration();
        $configuration->setSource($source);
        $configuration->setUseAnnotationCache($cache);
        $configuration->setAnnotationCachePath($this->cacheDirectory);
        $configuration->setDebugMode($debug);
        return new AnnotationReader($configuration);
    }

    /**
     * @return void
     */
    public function testDefaultModeAndLegacyConstructor(): void {
        $reader = new AnnotationReader(new Configuration());
        self::assertSame('doc', $reader->getClassAnnotations(MixedMetadataFixture::class, RoutePrefix::class)->first()->getParameters()['value']);
        self::assertSame('legacy', $reader->getClassAnnotations(MixedMetadataFixture::class, LegacyMarker::class)->first()->getParameters()['value']);
        self::assertSame('/doc', $reader->getMethodAnnotations(MixedMetadataFixture::class, 'action', Route::class)->first()->getRoute());
        self::assertSame('doc', $reader->getPropertyAnnotations(MixedMetadataFixture::class, 'name', Column::class)->first()->getName());
    }

    /**
     * Confirms fixture annotations have equivalent docblock and native forms, including disk cache.
     * @return void
     */
    public function testPackageFixtureAnnotationsInBothModes(): void {
        foreach ([Configuration::SOURCE_DOCBLOCKS, Configuration::SOURCE_ATTRIBUTES] as $source) {
            for ($pass = 0; $pass < 2; $pass++) {
                $reader = $this->reader($source, true);
                $cacheable = $reader->getClassAnnotations(BaseService::class, CacheableTest::class)->first();
                self::assertSame(['ttl' => 3600], $cacheable->getParameters());
                self::assertSame(3600, $cacheable->getTtl());
                self::assertSame(['id' => 100, 'priority' => null], $reader->getClassAnnotations(BaseService::class, AnotherTest::class)->first()->getParameters());
                $another = $reader->getClassAnnotations(UserService::class, AnotherTest::class)->first();
                self::assertSame(['id' => 200, 'priority' => 'high'], $another->getParameters());
                self::assertSame(200, $another->getId());
                self::assertSame('high', $another->getPriority());
                $method = $reader->getMethodAnnotations(UserService::class, 'annotatedMethod', FixtureTest::class)->first();
                self::assertSame(['name' => 'child_method'], $method->getParameters());
                self::assertSame('child_method', $method->getName());
                self::assertSame(['name' => 'child_property'], $reader->getPropertyAnnotations(UserService::class, 'annotatedProperty', FixtureTest::class)->first()->getParameters());
            }
        }
    }

    /**
     * @return void
     */
    public function testNativeAttributesAndFilters(): void {
        $reader = $this->reader(Configuration::SOURCE_ATTRIBUTES);
        self::assertSame(['value' => 'class', 'priority' => 2], $reader->getClassAnnotations(MixedMetadataFixture::class, CustomMarker::class)->first()->getParameters());
        self::assertCount(1, $reader->getClassAnnotations(MixedMetadataFixture::class));
        self::assertCount(2, $reader->getMethodAnnotations(MixedMetadataFixture::class, 'action', Route::class));
        self::assertSame('/native', $reader->getMethodAnnotations(MixedMetadataChild::class, 'action', Route::class)->first()->getRoute());
        self::assertSame('native', $reader->getPropertyAnnotations(MixedMetadataChild::class, 'name', Column::class)->first()->getName());
        self::assertTrue($reader->methodHasAnnotation(MixedMetadataFixture::class, 'action', Route::class));
        self::assertTrue($reader->propertyHasAnnotation(MixedMetadataFixture::class, 'name', Column::class));
        self::assertFalse($reader->classHasAnnotation(MixedMetadataFixture::class, RoutePrefix::class));
        self::assertSame(25, $reader->getPropertyAnnotations(MixedMetadataFixture::class, 'name', Column::class)->first()->getLimit());
        self::assertSame(2, $reader->getPropertyAnnotations(MixedMetadataFixture::class, 'name', Length::class)->first()->getMin());
        self::assertSame('attribute_fixture', $reader->getClassAnnotations(ObjectQuelAttributeFixture::class, Table::class)->first()->getName());
    }

    /**
     * @return void
     */
    public function testExplicitStringParsingInBothModes(): void {
        foreach ([Configuration::SOURCE_DOCBLOCKS, Configuration::SOURCE_ATTRIBUTES] as $source) {
            $reader = $this->reader($source);
            self::assertSame('doc', $reader->getAnnotations('@' . LegacyMarker::class . '(value="doc")')->first()->getParameters()['value']);
        }
    }

    /**
     * @return void
     */
    public function testNativeInstantiationErrorsIncludeMemberContext(): void {
        $reader = $this->reader(Configuration::SOURCE_ATTRIBUTES);
        try {
            $reader->getClassAnnotations(InvalidTargetFixture::class);
            self::fail('Expected invalid attribute target to fail.');
        } catch (AnnotationReaderException $e) {
            self::assertStringContainsString('InvalidTargetFixture', $e->getMessage());
        }
        try {
            $reader->getMethodAnnotations(InvalidArgumentsFixture::class, 'action');
            self::fail('Expected invalid attribute arguments to fail.');
        } catch (AnnotationReaderException $e) {
            self::assertStringContainsString('InvalidArgumentsFixture::action', $e->getMessage());
        }
    }

    /**
     * @return void
     */
    public function testCacheModesRemainSeparateAndClearSelectedFiles(): void {
        $docblocks = $this->reader(Configuration::SOURCE_DOCBLOCKS, true);
        $attributes = $this->reader(Configuration::SOURCE_ATTRIBUTES, true);
        self::assertSame('doc', $docblocks->getClassAnnotations(MixedMetadataFixture::class, RoutePrefix::class)->first()->getParameters()['value']);
        self::assertSame('class', $attributes->getClassAnnotations(MixedMetadataFixture::class, CustomMarker::class)->first()->getParameters()['value']);
        self::assertCount(2, glob($this->cacheDirectory . DIRECTORY_SEPARATOR . '*.cache') ?: []);
        self::assertSame('/native', $this->reader(Configuration::SOURCE_ATTRIBUTES, true)->getMethodAnnotations(MixedMetadataFixture::class, 'action', Route::class)->first()->getRoute());
        $attributes->clearCacheByAnnotationClass(Route::class);
        self::assertCount(1, glob($this->cacheDirectory . DIRECTORY_SEPARATOR . '*.cache') ?: []);
        $docblocks->clearAllCaches();
        self::assertSame([], glob($this->cacheDirectory . DIRECTORY_SEPARATOR . '*.cache') ?: []);
    }

    /**
     * @return void
     */
    public function testDisabledMemoryDiskAndStaleCacheInBothModes(): void {
        foreach ([Configuration::SOURCE_DOCBLOCKS, Configuration::SOURCE_ATTRIBUTES] as $source) {
            $uncached = $this->reader($source);
            $first = $uncached->getClassAnnotations(MixedMetadataFixture::class)->first();
            self::assertSame($first, $uncached->getClassAnnotations(MixedMetadataFixture::class)->first());
            self::assertSame([], glob($this->cacheDirectory . DIRECTORY_SEPARATOR . '*.cache') ?: []);

            $cached = $this->reader($source, true);
            $parameters = $cached->getClassAnnotations(MixedMetadataFixture::class)->first()->getParameters();
            $files = glob($this->cacheDirectory . DIRECTORY_SEPARATOR . '*.cache') ?: [];
            self::assertCount(1, $files);
            self::assertSame($parameters, $this->reader($source, true)->getClassAnnotations(MixedMetadataFixture::class)->first()->getParameters());

            touch($files[0], time() - 3600);
            clearstatcache();
            $this->reader($source, true, true)->getClassAnnotations(MixedMetadataFixture::class);
            clearstatcache();
            self::assertGreaterThan(time() - 3600, filemtime($files[0]));
            $cached->clearAllCaches();
            self::assertSame([], glob($this->cacheDirectory . DIRECTORY_SEPARATOR . '*.cache') ?: []);
        }
    }

    /**
     * @return void
     */
    public function testNestedObjectsAreNeverWrittenAsIncompleteJson(): void {
        $reader = $this->reader(Configuration::SOURCE_ATTRIBUTES, true);
        $annotation = $reader->getClassAnnotations(NestedValueFixture::class, NestedMarker::class)->first();
        self::assertInstanceOf(CustomMarker::class, $annotation->getParameters()['value']);
        self::assertSame([], glob($this->cacheDirectory . DIRECTORY_SEPARATOR . '*.cache') ?: []);
        $docblocks = $this->reader(Configuration::SOURCE_DOCBLOCKS, true);
        $nested = $docblocks->getClassAnnotations(DocblockNestedValueFixture::class, NestedMarker::class)->first();
        self::assertInstanceOf(CustomMarker::class, $nested->getParameters()['value']);
        self::assertSame([], glob($this->cacheDirectory . DIRECTORY_SEPARATOR . '*.cache') ?: []);
    }

    /**
     * @return void
     */
    public function testOldAndCorruptCacheEntriesAreHandledSafely(): void {
        $file = $this->cacheDirectory . DIRECTORY_SEPARATOR . str_replace('\\', '#', MixedMetadataFixture::class) . '.cache';
        file_put_contents($file, json_encode([
            'class' => [['class' => RoutePrefix::class, 'parameters' => ['value' => 'old']]],
            'methods' => [],
            'properties' => [],
        ], JSON_THROW_ON_ERROR));
        $reader = $this->reader(Configuration::SOURCE_DOCBLOCKS, true);
        $reader->clearCacheByAnnotationClass(RoutePrefix::class);
        self::assertFileDoesNotExist($file);

        file_put_contents($file, json_encode([
            'version' => 2,
            'class' => [['class' => RoutePrefix::class, 'parameters' => ['unknown' => true]]],
            'methods' => [],
            'properties' => [],
        ], JSON_THROW_ON_ERROR));
        self::assertSame('doc', $this->reader(Configuration::SOURCE_DOCBLOCKS, true)->getClassAnnotations(MixedMetadataFixture::class, RoutePrefix::class)->first()->getParameters()['value']);
    }

    /**
     * @return void
     */
    public function testTargetedClearFindsPropertyAnnotations(): void {
        $reader = $this->reader(Configuration::SOURCE_ATTRIBUTES, true);
        $reader->getPropertyAnnotations(MixedMetadataFixture::class, 'name', Column::class);
        self::assertCount(1, glob($this->cacheDirectory . DIRECTORY_SEPARATOR . '*.cache') ?: []);
        $reader->clearCacheByAnnotationClass(Column::class);
        self::assertSame([], glob($this->cacheDirectory . DIRECTORY_SEPARATOR . '*.cache') ?: []);
    }

    /**
     * @return void
     */
    public function testInvalidSourceIsRejected(): void {
        $configuration = new Configuration();
        $this->expectException(\InvalidArgumentException::class);
        $configuration->setSource('both');
    }

    /**
     * @return void
     */
    public function testObjectQuelConfigurationSelectsTheReaderSource(): void {
        $configuration = new \Quellabs\ObjectQuel\Configuration();
        $configuration->setAnnotationSource(Configuration::SOURCE_ATTRIBUTES);
        self::assertSame(Configuration::SOURCE_ATTRIBUTES, $configuration->getAnnotationSource());
    }

    /**
     * @return void
     */
    public function testCanvasRoutingAndSignalsUseTheConfiguredAttributeReader(): void {
        $kernel = new Kernel(['annotation_source' => Configuration::SOURCE_ATTRIBUTES]);
        $controllers = new class($kernel) extends ControllersDiscovery {
            /**
             * @return array<class-string>
             */
            public function fetch(): array {
                return [NativeCanvasController::class];
            }
        };
        $analyzer = new RouteSegmentAnalyzer();
        $discovery = new RouteDiscovery($kernel, $controllers, $analyzer, new RoutePatternCompiler($analyzer));
        $routes = $discovery->buildRoutesFromControllers();
        self::assertCount(1, $routes);
        self::assertSame('/native/ping', $routes[0]['route_path']);
        self::assertSame(['GET'], $routes[0]['http_methods']);

        $connector = new SignalConnector($kernel, NativeCanvasController::class);
        $listeners = (new \ReflectionProperty(SignalConnector::class, 'listenerMap'))->getValue($connector);
        self::assertSame('ping', $listeners['demo.ping'][0]['method']);
        self::assertSame(3, $listeners['demo.ping'][0]['priority']);
    }

    /**
     * @return void
     */
    public function testAspectResolverKeepsOpenEndedInterceptWithInDocblockMode(): void {
        $docblockAspects = (new AspectResolver($this->reader(Configuration::SOURCE_DOCBLOCKS)))->resolve(DocblockOnlyAspectFixture::class, 'run');
        self::assertSame(['ttl' => 30], $docblockAspects[0]['parameters']);

        // DocblockOnlyAspectFixture declares only docblock metadata, so reading it in
        // attribute mode legitimately finds nothing — this is about the fixture, not
        // about whether InterceptWith itself supports native attributes (it does; see
        // testOpenEndedCanvasAnnotationsWorkIdenticallyAsNativeAttributes below).
        $attributeAspects = (new AspectResolver($this->reader(Configuration::SOURCE_ATTRIBUTES)))->resolve(DocblockOnlyAspectFixture::class, 'run');
        self::assertSame([], $attributeAspects);
    }

    /**
     * CacheContext, InterceptWith, and WithContext accept arbitrary caller-defined keys
     * through a variadic constructor tail, so the exact same named arguments work whether
     * declared as a docblock annotation or as a native PHP attribute.
     * @return void
     */
    public function testOpenEndedCanvasAnnotationsWorkIdenticallyAsNativeAttributes(): void {
        $docblocks = $this->reader(Configuration::SOURCE_DOCBLOCKS);
        $cache = $docblocks->getMethodAnnotations(DocblockOnlyAspectFixture::class, 'run', CacheContext::class)->first();
        self::assertSame(['namespace' => 'users', 'driver' => 'redis'], $cache->getParameters());

        $context = $docblocks->getMethodAnnotations(DocblockOnlyAspectFixture::class, 'run', WithContext::class)->first();
        self::assertSame('engine', $context->getParameter());
        self::assertSame(['context' => 'blade'], $context->getContext());

        $attributes = $this->reader(Configuration::SOURCE_ATTRIBUTES);

        $nativeCache = $attributes->getMethodAnnotations(NativeAttributeAspectFixture::class, 'run', CacheContext::class)->first();
        self::assertSame(['namespace' => 'users', 'driver' => 'redis'], $nativeCache->getParameters());

        $nativeContext = $attributes->getMethodAnnotations(NativeAttributeAspectFixture::class, 'run', WithContext::class)->first();
        self::assertSame('engine', $nativeContext->getParameter());
        self::assertSame(['context' => 'blade'], $nativeContext->getContext());

        $nativeAspects = (new AspectResolver($attributes))->resolve(NativeAttributeAspectFixture::class, 'run');
        self::assertSame(\stdClass::class, $nativeAspects[0]['class']);
        self::assertSame(['ttl' => 30], $nativeAspects[0]['parameters']);
    }

    /**
     * @return void
     */
    public function testTraitRejectsMissingDeclaredProperty(): void {
        $annotation = new class('x') implements AnnotationInterface {
            use ReflectsAnnotationParameters;
            /**
             * @param string $value
             * @return void
             */
            public function __construct(string $value) {}
        };
        $this->expectException(\ReflectionException::class);
        $annotation->getParameters();
    }
}
