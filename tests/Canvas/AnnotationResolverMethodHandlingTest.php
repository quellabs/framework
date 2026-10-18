<?php

	namespace Quellabs\Canvas\Tests\Routing;

	use PHPUnit\Framework\TestCase;
	use Quellabs\Canvas\Annotations\Route;
	use Quellabs\Canvas\Exceptions\MethodNotAllowedException;
	use Quellabs\Canvas\Exceptions\RouteNotFoundException;
	use Quellabs\Canvas\Kernel;
	use Quellabs\Canvas\Routing\AnnotationResolver;
	use Quellabs\Canvas\Routing\Components\RouteCandidateFilter;
	use Quellabs\Canvas\Routing\Components\RoutePatternCompiler;
	use Quellabs\Canvas\Routing\Components\RouteSegmentAnalyzer;
	use Symfony\Component\HttpFoundation\Request;

	/**
	 * Tests the method-aware routing behavior of AnnotationResolver::resolve()/resolveAll():
	 * 404 vs 405, HEAD falling back to GET, and the implicit HEAD/OPTIONS methods
	 * reported on a 405's Allow list.
	 *
	 * Route discovery itself (scanning controllers from disk) is exercised elsewhere;
	 * here the resolver's route index is built directly from hand-constructed route
	 * definitions and injected via reflection, so these tests exercise only the
	 * matching/decision logic under test, not controller discovery.
	 */
	class AnnotationResolverMethodHandlingTest extends TestCase {

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
		private function buildRoute(string $path, array $methods, string $method = 'action'): array {
			return [
				'http_methods'     => $methods,
				'controller'       => 'FixtureController',
				'method'           => $method,
				'route'            => new Route(['value' => $path, 'methods' => $methods]),
				'route_path'       => $path,
				'priority'         => $this->analyzer->calculateRoutePriority($path),
				'compiled_pattern' => $this->compiler->compileRoute($path),
			];
		}

		/**
		 * @param list<array<string, mixed>> $routes
		 * @param array<string, mixed> $kernelConfig
		 */
		private function resolverFor(array $routes, array $kernelConfig = []): AnnotationResolver {
			$index = $this->filter->buildRouteIndex($routes);
			$resolver = new AnnotationResolver(new Kernel($kernelConfig));

			$property = new \ReflectionProperty(AnnotationResolver::class, 'routeIndex');
			$property->setAccessible(true);
			$property->setValue($resolver, $index);

			return $resolver;
		}

		// -------------------------------------------------------------------------
		// 404 vs 405
		// -------------------------------------------------------------------------

		public function testResolveThrowsRouteNotFoundWhenNoPathMatchesAtAll(): void {
			$resolver = $this->resolverFor([$this->buildRoute('/ping', ['GET'])]);

			$this->expectException(RouteNotFoundException::class);
			$resolver->resolve(Request::create('/nonexistent', 'GET'));
		}

		public function testResolveThrowsMethodNotAllowedWhenPathMatchesButMethodDoesNot(): void {
			$resolver = $this->resolverFor([$this->buildRoute('/ping', ['GET'])]);

			$this->expectException(MethodNotAllowedException::class);
			$resolver->resolve(Request::create('/ping', 'DELETE'));
		}

		public function testMethodNotAllowedCarriesTheDeclaredAndImplicitMethods(): void {
			$resolver = $this->resolverFor([
				$this->buildRoute('/ping', ['GET']),
				$this->buildRoute('/ping', ['POST']),
			]);

			try {
				$resolver->resolve(Request::create('/ping', 'DELETE'));
				$this->fail('Expected MethodNotAllowedException');
			} catch (MethodNotAllowedException $e) {
				// GET/POST declared; HEAD implied by GET; OPTIONS always implied.
				$this->assertSame(['GET', 'HEAD', 'OPTIONS', 'POST'], $e->getAllowedMethods());
			}
		}

		public function testMethodNotAllowedOmitsHeadWhenGetIsNotDeclared(): void {
			$resolver = $this->resolverFor([$this->buildRoute('/ping', ['POST'])]);

			try {
				$resolver->resolve(Request::create('/ping', 'DELETE'));
				$this->fail('Expected MethodNotAllowedException');
			} catch (MethodNotAllowedException $e) {
				$this->assertSame(['OPTIONS', 'POST'], $e->getAllowedMethods());
			}
		}

		public function testResolveAllReturnsEmptyForAMethodMismatchWithoutThrowing(): void {
			// resolveAll() is also used by the routes:match introspection command,
			// which must not blow up on a plain mismatch — only resolve() escalates.
			$resolver = $this->resolverFor([$this->buildRoute('/ping', ['GET'])]);

			$this->assertSame([], $resolver->resolveAll(Request::create('/ping', 'DELETE')));
		}

		// -------------------------------------------------------------------------
		// HEAD falls back to GET
		// -------------------------------------------------------------------------

		public function testResolveAllFallsBackToGetForHeadWhenNoExplicitHeadRouteExists(): void {
			$resolver = $this->resolverFor([$this->buildRoute('/ping', ['GET'], 'getAction')]);

			$result = $resolver->resolveAll(Request::create('/ping', 'HEAD'));

			$this->assertCount(1, $result);
			$this->assertSame('getAction', $result[0]['method']);
		}

		public function testResolveAllPrefersAnExplicitHeadRouteOverTheGetFallback(): void {
			$resolver = $this->resolverFor([
				$this->buildRoute('/ping', ['GET'], 'getAction'),
				$this->buildRoute('/ping', ['HEAD'], 'headAction'),
			]);

			$result = $resolver->resolveAll(Request::create('/ping', 'HEAD'));

			$this->assertCount(1, $result);
			$this->assertSame('headAction', $result[0]['method']);
		}

		public function testResolveAllHeadReturnsEmptyWhenNoGetRouteExistsEither(): void {
			$resolver = $this->resolverFor([$this->buildRoute('/ping', ['POST'])]);

			$this->assertSame([], $resolver->resolveAll(Request::create('/ping', 'HEAD')));
		}

		// -------------------------------------------------------------------------
		// Trailing slash (path-only, no query string)
		// -------------------------------------------------------------------------

		public function testTrailingSlashMismatchIsNotMaskedByAQueryString(): void {
			// Previously the comparison ran against Request::getRequestUri(), which still
			// carries "?x=1" and does not end in "/" — masking a real trailing-slash
			// mismatch between "/ping/" (request) and "/ping" (route). getPathInfo()
			// strips the query string, so the mismatch must now be caught correctly.
			$resolver = $this->resolverFor(
				[$this->buildRoute('/ping', ['GET'])],
				['match_trailing_slashes' => true]
			);

			$result = $resolver->resolveAll(Request::create('/ping/?x=1', 'GET'));

			$this->assertSame([], $result);
		}

		public function testTrailingSlashMatchStillSucceedsWithAQueryString(): void {
			$resolver = $this->resolverFor(
				[$this->buildRoute('/ping', ['GET'])],
				['match_trailing_slashes' => true]
			);

			$result = $resolver->resolveAll(Request::create('/ping?x=1', 'GET'));

			$this->assertCount(1, $result);
		}

		// -------------------------------------------------------------------------
		// Trailing slash interacting with 404-vs-405 and auto-OPTIONS
		// -------------------------------------------------------------------------

		/**
		 * Under strict trailing-slash matching, a slash mismatch means the path
		 * itself doesn't match — that must stay a 404, not a false 405, even though
		 * a route with the same segments (minus the slash) does exist.
		 */
		public function testStrictTrailingSlashMismatchIsA404NotA405(): void {
			$resolver = $this->resolverFor(
				[$this->buildRoute('/ping', ['GET'])],
				['match_trailing_slashes' => true]
			);

			$this->expectException(RouteNotFoundException::class);
			$resolver->resolve(Request::create('/ping/', 'DELETE'));
		}

		public function testStrictTrailingSlashExactMatchStillProducesA405(): void {
			$resolver = $this->resolverFor(
				[$this->buildRoute('/ping', ['GET'])],
				['match_trailing_slashes' => true]
			);

			try {
				$resolver->resolve(Request::create('/ping', 'DELETE'));
				$this->fail('Expected MethodNotAllowedException');
			} catch (MethodNotAllowedException $e) {
				$this->assertSame(['GET', 'HEAD', 'OPTIONS'], $e->getAllowedMethods());
			}
		}

		/**
		 * Same slash-mismatch case, but for OPTIONS: it must not be auto-answered
		 * with a 200/Allow response, since the path genuinely doesn't match either.
		 */
		public function testStrictTrailingSlashMismatchLeavesOptionsAs404Too(): void {
			$resolver = $this->resolverFor(
				[$this->buildRoute('/ping', ['GET'])],
				['match_trailing_slashes' => true]
			);

			$this->expectException(RouteNotFoundException::class);
			$resolver->resolve(Request::create('/ping/', 'OPTIONS'));
		}

		public function testLenientTrailingSlashStillProducesA405RegardlessOfSlash(): void {
			$resolver = $this->resolverFor(
				[$this->buildRoute('/ping', ['GET'])],
				['match_trailing_slashes' => false]
			);

			try {
				$resolver->resolve(Request::create('/ping/', 'DELETE'));
				$this->fail('Expected MethodNotAllowedException');
			} catch (MethodNotAllowedException $e) {
				$this->assertSame(['GET', 'HEAD', 'OPTIONS'], $e->getAllowedMethods());
			}
		}
	}
