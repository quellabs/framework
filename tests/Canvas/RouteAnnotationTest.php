<?php
	
	namespace Quellabs\Canvas\Tests\Annotations;
	
	use PHPUnit\Framework\TestCase;
	use Quellabs\Canvas\Annotations\Route;
	
	/**
	 * Unit tests for the Route annotation class.
	 *
	 * Covers constructor validation, HTTP method parsing/defaulting,
	 * getRoute() URL stripping, fallback handling, and error cases.
	 */
	class RouteAnnotationTest extends TestCase {
		
		// -------------------------------------------------------------------------
		// Constructor validation
		// -------------------------------------------------------------------------
		
		public function testConstructorThrowsWhenValueMissing(): void {
			$this->expectException(\InvalidArgumentException::class);
			new Route([]);
		}
		
		public function testConstructorThrowsWhenValueIsNotString(): void {
			$this->expectException(\InvalidArgumentException::class);
			new Route(['value' => 123]);
		}
		
		public function testConstructorThrowsWhenFallbackIsNotString(): void {
			$this->expectException(\InvalidArgumentException::class);
			new Route(['value' => '/users', 'fallback' => 42]);
		}
		
		public function testConstructorThrowsWhenMethodsIsNotArray(): void {
			$this->expectException(\InvalidArgumentException::class);
			new Route(['value' => '/users', 'methods' => 'GET']);
		}
		
		public function testConstructorThrowsWhenMethodsContainsNonString(): void {
			$this->expectException(\InvalidArgumentException::class);
			new Route(['value' => '/users', 'methods' => [1, 2]]);
		}
		
		// -------------------------------------------------------------------------
		// getRoute — plain paths
		// -------------------------------------------------------------------------
		
		public function testGetRouteReturnsPlainPath(): void {
			$route = new Route(['value' => '/users/{id}']);
			$this->assertSame('/users/{id}', $route->getRoute());
		}
		
		public function testGetRouteStripsHttpSchemeAndHost(): void {
			$route = new Route(['value' => 'http://example.com/api/users']);
			$this->assertSame('/api/users', $route->getRoute());
		}
		
		public function testGetRouteStripsHttpsSchemeAndHost(): void {
			$route = new Route(['value' => 'https://example.com/api/v1/items']);
			$this->assertSame('/api/v1/items', $route->getRoute());
		}
		
		public function testGetRoutePreservesConfigReference(): void {
			// Config references like "mollie::redirectUrl" must not be parsed as URLs
			$route = new Route(['value' => 'mollie::redirectUrl']);
			$this->assertSame('mollie::redirectUrl', $route->getRoute());
		}
		
		// -------------------------------------------------------------------------
		// getMethods — defaulting and parsing
		// -------------------------------------------------------------------------
		
		public function testGetMethodsDefaultsToGetAndHead(): void {
			$route = new Route(['value' => '/users']);
			$this->assertSame(['GET', 'HEAD'], $route->getMethods());
		}
		
		public function testGetMethodsDefaultsToGetAndHeadForEmptyMethodsArray(): void {
			$route = new Route(['value' => '/users', 'methods' => []]);
			$this->assertSame(['GET', 'HEAD'], $route->getMethods());
		}
		
		public function testGetMethodsReturnsSpecifiedMethods(): void {
			$route = new Route(['value' => '/users', 'methods' => ['POST', 'PUT']]);
			$this->assertSame(['POST', 'PUT'], $route->getMethods());
		}
		
		// -------------------------------------------------------------------------
		// getName / getFallback / getParameters
		// -------------------------------------------------------------------------
		
		public function testGetNameReturnsRouteValue(): void {
			$route = new Route(['value' => '/users']);
			$this->assertSame('/users', $route->getName());
		}
		
		public function testGetFallbackReturnsNullWhenNotSet(): void {
			$route = new Route(['value' => '/users']);
			$this->assertNull($route->getFallback());
		}
		
		public function testGetFallbackReturnsProvidedString(): void {
			$route = new Route(['value' => '/users', 'fallback' => '/home']);
			$this->assertSame('/home', $route->getFallback());
		}
		
		public function testGetParametersReturnsFullArray(): void {
			$params = ['value' => '/users', 'methods' => ['GET']];
			$route  = new Route($params);
			$this->assertSame($params, $route->getParameters());
		}
	}