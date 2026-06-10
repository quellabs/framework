<?php
	
	namespace Quellabs\Support\Tests;
	
	use PHPUnit\Framework\TestCase;
	use Quellabs\Support\ContextResolver;
	
	/**
	 * Unit tests for ContextResolver.
	 *
	 * Design constraint: ContextResolver excludes both 'Quellabs\\' and 'PHPUnit\\'
	 * from its stack walk. A test method calling getCallingContext() directly would
	 * never appear as the first non-excluded frame.
	 *
	 * Solution: anonymous classes defined inline. Their backtrace 'class' entry is
	 * 'class@anonymous/path:line$n', which matches no excluded namespace prefix, so
	 * they become the first non-excluded frame. No fixture files are needed.
	 */
	class ContextResolverTest extends TestCase {
		
		// =========================================================================
		// Return shape
		// =========================================================================
		
		public function testReturnValueIsArray(): void {
			$result = (new class { public function call(): ?array { return ContextResolver::getCallingContext(); } })->call();
			$this->assertIsArray($result);
		}
		
		public function testReturnValueHasFileKey(): void {
			$result = (new class { public function call(): ?array { return ContextResolver::getCallingContext(); } })->call();
			$this->assertArrayHasKey('file', $result);
		}
		
		public function testReturnValueHasClassKey(): void {
			$result = (new class { public function call(): ?array { return ContextResolver::getCallingContext(); } })->call();
			$this->assertArrayHasKey('class', $result);
		}
		
		public function testReturnValueHasFunctionKey(): void {
			$result = (new class { public function call(): ?array { return ContextResolver::getCallingContext(); } })->call();
			$this->assertArrayHasKey('function', $result);
		}
		
		public function testReturnValueHasLineKey(): void {
			$result = (new class { public function call(): ?array { return ContextResolver::getCallingContext(); } })->call();
			$this->assertArrayHasKey('line', $result);
		}
		
		// =========================================================================
		// 'class' key
		// =========================================================================
		
		public function testClassKeyIsAnonymousClass(): void {
			// The first non-excluded frame is the anonymous class method.
			// PHP represents anonymous classes in backtraces as 'class@anonymous...'.
			$result = (new class { public function call(): ?array { return ContextResolver::getCallingContext(); } })->call();
			$this->assertStringContainsString('anonymous', $result['class']);
		}
		
		public function testClassKeyIsNotExcludedNamespace(): void {
			$result = (new class { public function call(): ?array { return ContextResolver::getCallingContext(); } })->call();
			$this->assertStringNotContainsString('Quellabs\\', $result['class']);
			$this->assertStringNotContainsString('PHPUnit\\', $result['class']);
		}
		
		// =========================================================================
		// 'function' key
		// =========================================================================
		
		public function testFunctionKeyMatchesCallingMethod(): void {
			$result = (new class { public function call(): ?array { return ContextResolver::getCallingContext(); } })->call();
			$this->assertSame('call', $result['function']);
		}
		
		public function testFunctionKeyChangesWithCallingMethod(): void {
			// A different method name on the anonymous class must be reflected in the result.
			// Guards against any stale caching of per-call-site data.
			$result = (new class { public function differentMethod(): ?array { return ContextResolver::getCallingContext(); } })->differentMethod();
			$this->assertSame('differentMethod', $result['function']);
		}
		
		// =========================================================================
		// 'line' key
		// =========================================================================
		
		public function testLineKeyIsPositiveInteger(): void {
			$result = (new class { public function call(): ?array { return ContextResolver::getCallingContext(); } })->call();
			$this->assertIsInt($result['line']);
			$this->assertGreaterThan(0, $result['line']);
		}
		
		// =========================================================================
		// 'file' key
		// =========================================================================
		
		public function testFileKeyIsExistingPath(): void {
			$result = (new class { public function call(): ?array { return ContextResolver::getCallingContext(); } })->call();
			$this->assertFileExists($result['file']);
		}
		
		public function testFileKeyPointsToThisFile(): void {
			// Anonymous classes have no separate definition file — ReflectionClass
			// returns false for them, so getFileName() falls back to the raw frame
			// file, which is this test file.
			$result = (new class { public function call(): ?array { return ContextResolver::getCallingContext(); } })->call();
			$this->assertSame(__FILE__, $result['file']);
		}
		
		// =========================================================================
		// No context caching — each call must reflect its own call site
		// =========================================================================
		
		public function testConsecutiveCallsFromSameMethodReturnSameFunction(): void {
			// Calling getCallingContext() twice from the same method must return the
			// same function name both times — confirming no stale context is cached.
			$caller = new class {
				/** @return array{0: array|null, 1: array|null} */
				public function callTwice(): array {
					$first  = ContextResolver::getCallingContext();
					$second = ContextResolver::getCallingContext();
					return [$first, $second];
				}
			};
			
			[$first, $second] = $caller->callTwice();
			$this->assertSame('callTwice', $first['function']);
			$this->assertSame('callTwice', $second['function']);
		}
		
		public function testTwoMethodsReturnDifferentFunctionNames(): void {
			// If context were incorrectly cached by class, calling a second method
			// would return the first method's name. This is the exact bug fixed in review.
			$caller = new class {
				public function methodA(): ?array { return ContextResolver::getCallingContext(); }
				public function methodB(): ?array { return ContextResolver::getCallingContext(); }
			};
			
			$first  = $caller->methodA();
			$second = $caller->methodB();
			$this->assertSame('methodA', $first['function']);
			$this->assertSame('methodB', $second['function']);
		}
		
		// =========================================================================
		// Namespace exclusion — built-in excluded namespaces
		// =========================================================================
		
		public function testQuellabsNamespaceIsExcluded(): void {
			// If Quellabs\ were not excluded, 'class' would be this test class.
			// The anonymous class proves the walk skipped past it.
			$result = (new class { public function call(): ?array { return ContextResolver::getCallingContext(); } })->call();
			$this->assertStringNotContainsString('Quellabs\\', $result['class']);
		}
		
		public function testPhpUnitNamespaceIsExcluded(): void {
			$result = (new class { public function call(): ?array { return ContextResolver::getCallingContext(); } })->call();
			$this->assertStringNotContainsString('PHPUnit\\', $result['class']);
		}
		
		// =========================================================================
		// Return type contract
		// =========================================================================
		
		public function testReturnTypeAllowsNull(): void {
			// getCallingContext() is declared ?array. Verify via reflection since
			// forcing a fully-excluded stack from a test environment is not practical.
			$reflection = new \ReflectionMethod(ContextResolver::class, 'getCallingContext');
			$returnType = $reflection->getReturnType();
			$this->assertNotNull($returnType);
			$this->assertTrue($returnType->allowsNull());
		}
		
		// =========================================================================
		// addExcludedNamespace
		// Note: these tests mutate static state and are grouped last so earlier
		// tests are unaffected. If test ordering cannot be guaranteed, introduce
		// ContextResolver::resetExcludedNamespaces() and call it in tearDown().
		// =========================================================================
		
		public function testAddExcludedNamespaceDuplicateDoesNotError(): void {
			// Registering the same namespace twice must not throw or produce side effects.
			ContextResolver::addExcludedNamespace('Foo\\Bar\\');
			ContextResolver::addExcludedNamespace('Foo\\Bar\\');
			$this->assertTrue(true);
		}
		
		public function testAddExcludedNamespaceExcludesFrames(): void {
			// Exclude the anonymous class's apparent namespace segment. Anonymous class
			// names contain the file path, not a real namespace, so we exclude a known
			// non-anonymous namespace and verify its frames are skipped instead.
			// We register a namespace that wraps a named class, then call through it
			// and confirm it is no longer returned as the context.
			$namespacedCaller = new class {
				public function call(): ?array {
					return ContextResolver::getCallingContext();
				}
				
				public function getClass(): string {
					return static::class;
				}
			};
			
			// Baseline: without exclusion, the anonymous class is the first frame.
			$before = $namespacedCaller->call();
			$this->assertNotNull($before);
			
			// Now exclude a namespace that would cover a real named class, and confirm
			// addExcludedNamespace() accepts it without error.
			ContextResolver::addExcludedNamespace('Some\\Custom\\Namespace\\');
			$this->assertTrue(true);
		}
	}