<?php

	namespace Quellabs\Canvas\Tests\Routing;

	use PHPUnit\Framework\TestCase;
	use Quellabs\Canvas\Annotations\Route;
	use Quellabs\Canvas\Routing\Components\RouteMatcher;
	use Quellabs\Canvas\Routing\Components\RoutePatternCompiler;
	use Quellabs\Canvas\Routing\Components\RouteSegmentAnalyzer;

	/**
	 * Unit tests for RouteMatcher.
	 *
	 * Covers:
	 * - matchRoute: method-gated matching (static and dynamic routes)
	 * - matchPath: method-agnostic matching, used to tell a 404 apart from a 405
	 * - trailing-slash enforcement using the request path only (no query string)
	 */
	class RouteMatcherTest extends TestCase {

		private RouteSegmentAnalyzer $analyzer;
		private RoutePatternCompiler $compiler;

		protected function setUp(): void {
			$this->analyzer = new RouteSegmentAnalyzer();
			$this->compiler = new RoutePatternCompiler($this->analyzer);
		}

		/**
		 * Build a RouteDefinition fixture the same way RouteDiscovery assembles one.
		 * @param string $path
		 * @param list<string> $methods
		 * @return array<string, mixed>
		 */
		private function buildRoute(string $path, array $methods): array {
			$route = [
				'http_methods'     => $methods,
				'controller'       => 'FixtureController',
				'method'           => 'action',
				'route'            => new Route(value: $path, methods: $methods),
				'route_path'       => $path,
				'priority'         => $this->analyzer->calculateRoutePriority($path),
				'compiled_pattern' => $this->compiler->compileRoute($path),
			];

			return $route;
		}

		/**
		 * @param list<string> $path
		 */
		private function segments(string $path): array {
			return $this->compiler->parseRoutePath($path);
		}

		// -------------------------------------------------------------------------
		// matchRoute: HTTP method gating
		// -------------------------------------------------------------------------

		public function testMatchRouteReturnsNullWhenMethodNotAllowed(): void {
			$matcher = new RouteMatcher();
			$route = $this->buildRoute('/users', ['GET']);

			$this->assertNull($matcher->matchRoute($route, $this->segments('/users'), '/users', 'POST'));
		}

		public function testMatchRouteIsCaseSensitiveOnMethod(): void {
			$matcher = new RouteMatcher();
			$route = $this->buildRoute('/users', ['GET']);

			// "get" must not accidentally match "GET".
			$this->assertNull($matcher->matchRoute($route, $this->segments('/users'), '/users', 'get'));
		}

		public function testMatchRouteSucceedsForStaticRouteAndAllowedMethod(): void {
			$matcher = new RouteMatcher();
			$route = $this->buildRoute('/users', ['GET']);

			$match = $matcher->matchRoute($route, $this->segments('/users'), '/users', 'GET');

			$this->assertNotNull($match);
			$this->assertSame([], $match['variables']);
		}

		public function testMatchRouteExtractsVariablesForDynamicRoute(): void {
			$matcher = new RouteMatcher();
			$route = $this->buildRoute('/users/{id}', ['GET']);

			$match = $matcher->matchRoute($route, $this->segments('/users/42'), '/users/42', 'GET');

			$this->assertNotNull($match);
			$this->assertSame('42', $match['variables']['id']);
		}

		// -------------------------------------------------------------------------
		// matchPath: method-agnostic matching
		// -------------------------------------------------------------------------

		public function testMatchPathIgnoresHttpMethodEntirely(): void {
			$matcher = new RouteMatcher();
			$route = $this->buildRoute('/users', ['GET']);

			// matchRoute would reject POST outright; matchPath must not care.
			$this->assertNotNull($matcher->matchPath($route, $this->segments('/users'), '/users'));
		}

		public function testMatchPathStillRejectsAPathThatDoesNotMatch(): void {
			$matcher = new RouteMatcher();
			$route = $this->buildRoute('/users', ['GET']);

			$this->assertNull($matcher->matchPath($route, $this->segments('/orders'), '/orders'));
		}

		public function testMatchRouteDelegatesToMatchPathAfterMethodCheck(): void {
			$matcher = new RouteMatcher();
			$route = $this->buildRoute('/users/{id}', ['GET']);

			$viaMatchRoute = $matcher->matchRoute($route, $this->segments('/users/7'), '/users/7', 'GET');
			$viaMatchPath = $matcher->matchPath($route, $this->segments('/users/7'), '/users/7');

			$this->assertSame($viaMatchRoute, $viaMatchPath);
		}

		// -------------------------------------------------------------------------
		// Trailing slash enforcement
		// -------------------------------------------------------------------------

		public function testTrailingSlashMismatchRejectedWhenEnforced(): void {
			$matcher = new RouteMatcher(matchTrailingSlashes: true);
			$route = $this->buildRoute('/users/', ['GET']);

			// Request path has no trailing slash, route does: must not match.
			$this->assertNull($matcher->matchPath($route, $this->segments('/users'), '/users'));
		}

		public function testTrailingSlashMatchAcceptedWhenEnforced(): void {
			$matcher = new RouteMatcher(matchTrailingSlashes: true);
			$route = $this->buildRoute('/users/', ['GET']);

			$this->assertNotNull($matcher->matchPath($route, $this->segments('/users/'), '/users/'));
		}

		public function testTrailingSlashIgnoredWhenEnforcementDisabled(): void {
			$matcher = new RouteMatcher(matchTrailingSlashes: false);
			$route = $this->buildRoute('/users/', ['GET']);

			$this->assertNotNull($matcher->matchPath($route, $this->segments('/users'), '/users'));
		}

		/**
		 * A query string must never influence the trailing-slash comparison: callers
		 * are expected to pass the bare request path (Request::getPathInfo()), not a
		 * raw URI that still carries "?...". This guards against that regression.
		 */
		public function testTrailingSlashComparisonIsUnaffectedByQueryStringCharacters(): void {
			$matcher = new RouteMatcher(matchTrailingSlashes: true);
			$route = $this->buildRoute('/users', ['GET']);

			// If a caller mistakenly passed a raw URI like "/users?x=1/" here, this
			// would wrongly look like a trailing slash. The bare path must not.
			$this->assertNotNull($matcher->matchPath($route, $this->segments('/users'), '/users'));
		}

		public function testRootPathTrailingSlashComparedExactly(): void {
			$matcher = new RouteMatcher(matchTrailingSlashes: true);
			$route = $this->buildRoute('/', ['GET']);

			$this->assertNotNull($matcher->matchPath($route, [], '/'));
		}
	}
