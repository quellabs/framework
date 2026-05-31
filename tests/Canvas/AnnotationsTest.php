<?php
	
	namespace Quellabs\Canvas\Tests\Annotations;
	
	use PHPUnit\Framework\TestCase;
	use Quellabs\Canvas\Annotations\CacheContext;
	use Quellabs\Canvas\Annotations\InterceptWith;
	use Quellabs\Canvas\Annotations\ListenTo;
	use Quellabs\Canvas\Annotations\Route;
	use Quellabs\Canvas\Annotations\RoutePrefix;
	use Quellabs\Canvas\Annotations\WithContext;
	
	/**
	 * Unit tests for all Canvas annotation classes.
	 *
	 * Annotations are pure value objects: constructor validation, accessor methods,
	 * and any transformation logic (stripping URL schemes, trimming slashes, etc.).
	 * No annotation reader or framework boot required.
	 */
	class AnnotationsTest extends TestCase {
		
		// =========================================================================
		// Route
		// =========================================================================
		
		public function testRouteThrowsWhenValueMissing(): void {
			$this->expectException(\InvalidArgumentException::class);
			new Route([]);
		}
		
		public function testRouteThrowsWhenValueIsNotString(): void {
			$this->expectException(\InvalidArgumentException::class);
			new Route(['value' => 123]);
		}
		
		public function testRouteThrowsWhenFallbackIsNotString(): void {
			$this->expectException(\InvalidArgumentException::class);
			new Route(['value' => '/users', 'fallback' => 42]);
		}
		
		public function testRouteThrowsWhenMethodsIsNotArray(): void {
			$this->expectException(\InvalidArgumentException::class);
			new Route(['value' => '/users', 'methods' => 'GET']);
		}
		
		public function testRouteThrowsWhenMethodsContainsNonString(): void {
			$this->expectException(\InvalidArgumentException::class);
			new Route(['value' => '/users', 'methods' => [1, 2]]);
		}
		
		public function testRouteGetRouteReturnsPlainPath(): void {
			$route = new Route(['value' => '/users/{id}']);
			$this->assertSame('/users/{id}', $route->getRoute());
		}
		
		public function testRouteGetRouteStripsHttpSchemeAndHost(): void {
			$route = new Route(['value' => 'http://example.com/api/users']);
			$this->assertSame('/api/users', $route->getRoute());
		}
		
		public function testRouteGetRouteStripsHttpsSchemeAndHost(): void {
			$route = new Route(['value' => 'https://example.com/api/v1/items']);
			$this->assertSame('/api/v1/items', $route->getRoute());
		}
		
		public function testRouteGetRoutePreservesConfigReference(): void {
			$route = new Route(['value' => 'mollie::redirectUrl']);
			$this->assertSame('mollie::redirectUrl', $route->getRoute());
		}
		
		public function testRouteGetMethodsDefaultsToGetAndHead(): void {
			$route = new Route(['value' => '/users']);
			$this->assertSame(['GET', 'HEAD'], $route->getMethods());
		}
		
		public function testRouteGetMethodsDefaultsToGetAndHeadForEmptyArray(): void {
			$route = new Route(['value' => '/users', 'methods' => []]);
			$this->assertSame(['GET', 'HEAD'], $route->getMethods());
		}
		
		public function testRouteGetMethodsReturnsSpecifiedMethods(): void {
			$route = new Route(['value' => '/users', 'methods' => ['POST', 'PUT']]);
			$this->assertSame(['POST', 'PUT'], $route->getMethods());
		}
		
		public function testRouteGetNameReturnsRouteValue(): void {
			$route = new Route(['value' => '/users']);
			$this->assertSame('/users', $route->getName());
		}
		
		public function testRouteGetFallbackReturnsNullWhenNotSet(): void {
			$route = new Route(['value' => '/users']);
			$this->assertNull($route->getFallback());
		}
		
		public function testRouteGetFallbackReturnsProvidedString(): void {
			$route = new Route(['value' => '/users', 'fallback' => '/home']);
			$this->assertSame('/home', $route->getFallback());
		}
		
		public function testRouteGetParametersReturnsFullArray(): void {
			$params = ['value' => '/users', 'methods' => ['GET']];
			$this->assertSame($params, (new Route($params))->getParameters());
		}
		
		// =========================================================================
		// RoutePrefix
		// =========================================================================
		
		public function testRoutePrefixThrowsWhenValueMissing(): void {
			$this->expectException(\InvalidArgumentException::class);
			new RoutePrefix([]);
		}
		
		public function testRoutePrefixThrowsWhenValueIsNotString(): void {
			$this->expectException(\InvalidArgumentException::class);
			new RoutePrefix(['value' => 42]);
		}
		
		public function testRoutePrefixGetRoutePrefixReturnsValue(): void {
			$prefix = new RoutePrefix(['value' => 'api']);
			$this->assertSame('api', $prefix->getRoutePrefix());
		}
		
		public function testRoutePrefixStripsLeadingSlash(): void {
			$prefix = new RoutePrefix(['value' => '/api']);
			$this->assertSame('api', $prefix->getRoutePrefix());
		}
		
		public function testRoutePrefixStripsTrailingSlash(): void {
			$prefix = new RoutePrefix(['value' => 'api/']);
			$this->assertSame('api', $prefix->getRoutePrefix());
		}
		
		public function testRoutePrefixStripsBothSlashes(): void {
			$prefix = new RoutePrefix(['value' => '/api/v1/']);
			$this->assertSame('api/v1', $prefix->getRoutePrefix());
		}
		
		public function testRoutePrefixGetParametersReturnsFullArray(): void {
			$params = ['value' => '/api'];
			$this->assertSame($params, (new RoutePrefix($params))->getParameters());
		}
		
		// =========================================================================
		// WithContext
		// =========================================================================
		
		public function testWithContextThrowsWhenParameterMissing(): void {
			$this->expectException(\InvalidArgumentException::class);
			new WithContext([]);
		}
		
		public function testWithContextThrowsWhenParameterIsNotString(): void {
			$this->expectException(\InvalidArgumentException::class);
			new WithContext(['parameter' => 42]);
		}
		
		public function testWithContextGetParameterReturnsName(): void {
			$wc = new WithContext(['parameter' => 'templateEngine', 'context' => 'blade']);
			$this->assertSame('templateEngine', $wc->getParameter());
		}
		
		public function testWithContextGetContextExcludesParameterKey(): void {
			$wc      = new WithContext(['parameter' => 'db', 'context' => 'readonly']);
			$context = $wc->getContext();
			$this->assertArrayNotHasKey('parameter', $context);
		}
		
		public function testWithContextGetContextRemapsContextKeyToProvider(): void {
			// 'context' key is remapped to 'provider' for the DI system
			$wc      = new WithContext(['parameter' => 'db', 'context' => 'readonly']);
			$context = $wc->getContext();
			$this->assertArrayHasKey('provider', $context);
			$this->assertSame('readonly', $context['provider']);
		}
		
		public function testWithContextGetContextPreservesOtherKeys(): void {
			$wc      = new WithContext(['parameter' => 'db', 'context' => 'readonly', 'extra' => 'value']);
			$context = $wc->getContext();
			$this->assertSame('value', $context['extra']);
		}
		
		public function testWithContextGetParametersReturnsFullArray(): void {
			$params = ['parameter' => 'db', 'context' => 'readonly'];
			$this->assertSame($params, (new WithContext($params))->getParameters());
		}
		
		// =========================================================================
		// ListenTo
		// =========================================================================
		
		public function testListenToThrowsWhenValueMissing(): void {
			$this->expectException(\InvalidArgumentException::class);
			new ListenTo([]);
		}
		
		public function testListenToThrowsWhenValueIsNotString(): void {
			$this->expectException(\InvalidArgumentException::class);
			new ListenTo(['value' => 123]);
		}
		
		public function testListenToThrowsWhenPriorityIsNotInteger(): void {
			$this->expectException(\InvalidArgumentException::class);
			new ListenTo(['value' => 'user.created', 'priority' => 'high']);
		}
		
		public function testListenToGetNameReturnsSignalName(): void {
			$listenTo = new ListenTo(['value' => 'user.created']);
			$this->assertSame('user.created', $listenTo->getName());
		}
		
		public function testListenToGetPriorityDefaultsToZero(): void {
			$listenTo = new ListenTo(['value' => 'user.created']);
			$this->assertSame(0, $listenTo->getPriority());
		}
		
		public function testListenToGetPriorityReturnsProvidedValue(): void {
			$listenTo = new ListenTo(['value' => 'user.created', 'priority' => 10]);
			$this->assertSame(10, $listenTo->getPriority());
		}
		
		public function testListenToGetParametersReturnsFullArray(): void {
			$params = ['value' => 'user.created', 'priority' => 5];
			$this->assertSame($params, (new ListenTo($params))->getParameters());
		}
		
		// =========================================================================
		// InterceptWith
		// =========================================================================
		
		public function testInterceptWithThrowsWhenValueMissing(): void {
			$this->expectException(\InvalidArgumentException::class);
			new InterceptWith([]);
		}
		
		public function testInterceptWithThrowsWhenValueIsNotString(): void {
			$this->expectException(\InvalidArgumentException::class);
			new InterceptWith(['value' => 123]);
		}
		
		public function testInterceptWithThrowsWhenClassDoesNotExist(): void {
			$this->expectException(\InvalidArgumentException::class);
			new InterceptWith(['value' => 'NonExistent\\Class\\That\\Does\\Not\\Exist']);
		}
		
		public function testInterceptWithThrowsWhenPriorityIsNotInteger(): void {
			$this->expectException(\InvalidArgumentException::class);
			new InterceptWith(['value' => \stdClass::class, 'priority' => 'high']);
		}
		
		public function testInterceptWithGetInterceptClassReturnsClassName(): void {
			$iw = new InterceptWith(['value' => \stdClass::class]);
			$this->assertSame(\stdClass::class, $iw->getInterceptClass());
		}
		
		public function testInterceptWithGetPriorityDefaultsToZero(): void {
			$iw = new InterceptWith(['value' => \stdClass::class]);
			$this->assertSame(0, $iw->getPriority());
		}
		
		public function testInterceptWithGetPriorityReturnsProvidedValue(): void {
			$iw = new InterceptWith(['value' => \stdClass::class, 'priority' => 100]);
			$this->assertSame(100, $iw->getPriority());
		}
		
		public function testInterceptWithGetParametersReturnsFullArray(): void {
			$params = ['value' => \stdClass::class, 'priority' => 50];
			$this->assertSame($params, (new InterceptWith($params))->getParameters());
		}
		
		// =========================================================================
		// CacheContext
		// =========================================================================
		
		public function testCacheContextAcceptsEmptyParameters(): void {
			// CacheContext has no required parameters
			$cc = new CacheContext([]);
			$this->assertSame([], $cc->getParameters());
		}
		
		public function testCacheContextGetParametersReturnsProvidedArray(): void {
			$params = ['namespace' => 'products', 'ttl' => 3600];
			$cc     = new CacheContext($params);
			$this->assertSame($params, $cc->getParameters());
		}
		
		public function testCacheContextPreservesArbitraryKeys(): void {
			$cc = new CacheContext(['foo' => 'bar', 'baz' => 42]);
			$this->assertSame('bar', $cc->getParameters()['foo']);
			$this->assertSame(42, $cc->getParameters()['baz']);
		}
	}