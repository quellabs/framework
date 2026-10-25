<?php

	namespace Quellabs\Canvas\Tests\Routing;

	use PHPUnit\Framework\TestCase;
	use Quellabs\Canvas\Annotations\Route;
	use Quellabs\Canvas\Routing\Components\RouteCandidateFilter;
	use Quellabs\Canvas\Routing\Components\RouteMatcher;
	use Quellabs\Canvas\Routing\Components\RoutePatternCompiler;
	use Quellabs\Canvas\Routing\Components\RouteSegmentAnalyzer;

	/**
	 * Unit tests for RouteCandidateFilter.
	 *
	 * Covers:
	 * - getFilteredCandidates: method-scoped pre-filtering (existing behavior)
	 * - getPathMatchingCandidates: method-agnostic pre-filtering, used to tell a
	 *   404 (no route's path matches at all) apart from a 405 (path matches,
	 *   wrong method)
	 */
	class RouteCandidateFilterTest extends TestCase {

		private RouteSegmentAnalyzer $analyzer;
		private RoutePatternCompiler $compiler;
		private RouteCandidateFilter $filter;

		protected function setUp(): void {
			$this->analyzer = new RouteSegmentAnalyzer();
			$this->compiler = new RoutePatternCompiler($this->analyzer);
			$this->filter = new RouteCandidateFilter($this->analyzer);
		}

		/**
		 * @param list<string> $methods
		 * @return array<string, mixed>
		 */
		private function buildRoute(string $path, array $methods): array {
			return [
				'http_methods'     => $methods,
				'controller'       => 'FixtureController',
				'method'           => 'action',
				'route'            => new Route(value: $path, methods: $methods),
				'route_path'       => $path,
				'priority'         => $this->analyzer->calculateRoutePriority($path),
				'compiled_pattern' => $this->compiler->compileRoute($path),
			];
		}

		private function segments(string $path): array {
			return $this->compiler->parseRoutePath($path);
		}

		public function testGetFilteredCandidatesReturnsEmptyWhenMethodHasNoRoutes(): void {
			$index = $this->filter->buildRouteIndex([$this->buildRoute('/ping', ['GET'])]);

			$this->assertSame([], $this->filter->getFilteredCandidates($this->segments('/ping'), 'DELETE', $index));
		}

		public function testGetFilteredCandidatesReturnsRouteForMatchingMethod(): void {
			$index = $this->filter->buildRouteIndex([$this->buildRoute('/ping', ['GET'])]);

			$candidates = $this->filter->getFilteredCandidates($this->segments('/ping'), 'GET', $index);

			$this->assertCount(1, $candidates);
		}

		public function testGetPathMatchingCandidatesReturnsEveryMethodForTheSamePath(): void {
			$index = $this->filter->buildRouteIndex([
				$this->buildRoute('/ping', ['GET']),
				$this->buildRoute('/ping', ['POST']),
			]);

			// A method that neither declared route supports — getPathMatchingCandidates
			// must still surface both routes, since it ignores method entirely.
			$candidates = $this->filter->getPathMatchingCandidates($this->segments('/ping'), $index);

			$this->assertCount(2, $candidates);

			$methods = array_merge(...array_map(fn($route) => $route['http_methods'], $candidates));
			sort($methods);
			$this->assertSame(['GET', 'POST'], $methods);
		}

		public function testGetPathMatchingCandidatesForANonMatchingPathNeverActuallyMatch(): void {
			// This is a pre-filter, same contract as getFilteredCandidates: it may still
			// return a candidate whose literal segments differ (RouteMatcher::matchPath
			// makes the authoritative decision), but none of what it returns here must
			// ever actually match a path that shares nothing with any registered route.
			$index = $this->filter->buildRouteIndex([$this->buildRoute('/ping', ['GET'])]);
			$matcher = new RouteMatcher();

			$candidates = $this->filter->getPathMatchingCandidates($this->segments('/nonexistent'), $index);

			foreach ($candidates as $route) {
				$this->assertNull($matcher->matchPath($route, $this->segments('/nonexistent'), '/nonexistent'));
			}
		}

		public function testGetPathMatchingCandidatesHonorsSegmentCountFiltering(): void {
			$index = $this->filter->buildRouteIndex([
				$this->buildRoute('/ping', ['GET']),
				$this->buildRoute('/ping/extra', ['GET']),
			]);

			// "/ping" has one segment; the two-segment route must not be a candidate.
			$candidates = $this->filter->getPathMatchingCandidates($this->segments('/ping'), $index);

			$this->assertCount(1, $candidates);
			$this->assertSame('/ping', $candidates[0]['route_path']);
		}

		public function testGetPathMatchingCandidatesWorksForDynamicRoutes(): void {
			$index = $this->filter->buildRouteIndex([$this->buildRoute('/users/{id}', ['GET'])]);

			$candidates = $this->filter->getPathMatchingCandidates($this->segments('/users/42'), $index);

			$this->assertCount(1, $candidates);
		}

		public function testBuildRouteIndexPopulatesAllRoutesRegardlessOfMethod(): void {
			$routes = [
				$this->buildRoute('/ping', ['GET']),
				$this->buildRoute('/pong', ['POST']),
			];

			$index = $this->filter->buildRouteIndex($routes);

			$this->assertCount(2, $index['all_routes']);
		}
	}
