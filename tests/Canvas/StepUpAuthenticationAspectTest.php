<?php

	namespace Quellabs\Canvas\Tests\Security;

	use PHPUnit\Framework\TestCase;
	use Quellabs\Canvas\Exceptions\StaleAuthenticationException;
	use Quellabs\Canvas\Routing\Contracts\MethodContextInterface;
	use Quellabs\Canvas\Security\StepUpAuthenticationAspect;
	use Symfony\Component\HttpFoundation\Request;

	/**
	 * Unit tests for StepUpAuthenticationAspect.
	 *
	 * Each test constructs the aspect directly, builds a minimal MethodContext
	 * stub carrying a crafted Request with 'auth_time'/'auth_methods' attributes
	 * pre-set (as an authenticator aspect such as JwtAuthenticationAspect would),
	 * and asserts on request attributes (attribute mode) or thrown exceptions
	 * (exception mode). No JWT or authenticator involved — the aspect is
	 * auth-mechanism agnostic by design.
	 */
	class StepUpAuthenticationAspectTest extends TestCase {

		// =========================================================================
		// Helpers
		// =========================================================================

		/**
		 * Build a MethodContextInterface stub carrying the given Request.
		 * @param Request $request
		 * @return MethodContextInterface
		 */
		private function makeContext(Request $request): MethodContextInterface {
			$context = $this->createStub(MethodContextInterface::class);
			$context->method('getRequest')->willReturn($request);
			return $context;
		}

		/**
		 * Build a Request with 'auth_time' and optionally 'auth_methods' pre-set,
		 * as an authenticator aspect running earlier in the chain would leave it.
		 * @param int|float|string|null $authTime
		 * @param mixed $authMethods
		 * @return Request
		 */
		private function makeAuthenticatedRequest(int|float|string|null $authTime, mixed $authMethods = null): Request {
			$request = Request::create('/account/delete', 'POST');

			if ($authTime !== null) {
				$request->attributes->set('auth_time', $authTime);
			}

			if ($authMethods !== null) {
				$request->attributes->set('auth_methods', $authMethods);
			}

			return $request;
		}

		// =========================================================================
		// Constructor — configuration resolution
		// =========================================================================

		public function testConstructorThrowsWhenMaxAgeIsZero(): void {
			$this->expectException(\InvalidArgumentException::class);
			new StepUpAuthenticationAspect(maxAge: 0);
		}

		public function testConstructorThrowsWhenMaxAgeIsNegative(): void {
			$this->expectException(\InvalidArgumentException::class);
			new StepUpAuthenticationAspect(maxAge: -1);
		}

		// =========================================================================
		// Attribute mode — recency check
		// =========================================================================

		public function testFreshAuthTimeClearsRecentAuthError(): void {
			$request = $this->makeAuthenticatedRequest(time());
			$request->attributes->set('recent_auth_error', 'stale error');

			(new StepUpAuthenticationAspect(maxAge: 300))->before($this->makeContext($request));

			$this->assertNull($request->attributes->get('recent_auth_error'));
		}

		public function testAuthTimeExactlyAtBoundaryIsAccepted(): void {
			$request = $this->makeAuthenticatedRequest(time() - 300);

			(new StepUpAuthenticationAspect(maxAge: 300))->before($this->makeContext($request));

			$this->assertNull($request->attributes->get('recent_auth_error'));
		}

		public function testAuthTimeJustBeyondBoundarySetsRecentAuthError(): void {
			$request = $this->makeAuthenticatedRequest(time() - 301);

			(new StepUpAuthenticationAspect(maxAge: 300))->before($this->makeContext($request));

			$this->assertNotNull($request->attributes->get('recent_auth_error'));
		}

		public function testMissingAuthTimeSetsRecentAuthError(): void {
			$request = $this->makeAuthenticatedRequest(null);

			(new StepUpAuthenticationAspect(maxAge: 300))->before($this->makeContext($request));

			$this->assertNotNull($request->attributes->get('recent_auth_error'));
		}

		public function testNonNumericAuthTimeSetsRecentAuthError(): void {
			$request = $this->makeAuthenticatedRequest('not-a-timestamp');

			(new StepUpAuthenticationAspect(maxAge: 300))->before($this->makeContext($request));

			$this->assertNotNull($request->attributes->get('recent_auth_error'));
		}

		public function testFloatAuthTimeIsAccepted(): void {
			$request = $this->makeAuthenticatedRequest((float)time());

			(new StepUpAuthenticationAspect(maxAge: 300))->before($this->makeContext($request));

			$this->assertNull($request->attributes->get('recent_auth_error'));
		}

		public function testBeforeReturnsNullOnSuccess(): void {
			$request = $this->makeAuthenticatedRequest(time());

			$result = (new StepUpAuthenticationAspect(maxAge: 300))->before($this->makeContext($request));

			$this->assertNull($result);
		}

		public function testBeforeReturnsNullOnFailureInAttributeMode(): void {
			$request = $this->makeAuthenticatedRequest(null);

			$result = (new StepUpAuthenticationAspect(maxAge: 300))->before($this->makeContext($request));

			$this->assertNull($result);
		}

		// =========================================================================
		// Attribute mode — method check (requiredMethods)
		// =========================================================================

		public function testNoRequiredMethodsAllowsEmptyAuthMethods(): void {
			$request = $this->makeAuthenticatedRequest(time(), []);

			(new StepUpAuthenticationAspect(maxAge: 300))->before($this->makeContext($request));

			$this->assertNull($request->attributes->get('recent_auth_error'));
		}

		public function testMatchingRequiredMethodIsAccepted(): void {
			$request = $this->makeAuthenticatedRequest(time(), ['pwd']);

			(new StepUpAuthenticationAspect(maxAge: 300, requiredMethods: ['pwd']))->before($this->makeContext($request));

			$this->assertNull($request->attributes->get('recent_auth_error'));
		}

		public function testOneOfMultipleRequiredMethodsIsSufficient(): void {
			$request = $this->makeAuthenticatedRequest(time(), ['refresh', 'hwk']);

			(new StepUpAuthenticationAspect(maxAge: 300, requiredMethods: ['pwd', 'hwk']))->before($this->makeContext($request));

			$this->assertNull($request->attributes->get('recent_auth_error'));
		}

		public function testNonMatchingRequiredMethodSetsRecentAuthError(): void {
			// Fresh auth_time, but the token was only renewed via refresh — the
			// stateless equivalent of Symfony rejecting a remember-me-only session
			$request = $this->makeAuthenticatedRequest(time(), ['refresh']);

			(new StepUpAuthenticationAspect(maxAge: 300, requiredMethods: ['pwd']))->before($this->makeContext($request));

			$this->assertNotNull($request->attributes->get('recent_auth_error'));
		}

		public function testMissingAuthMethodsWithRequiredMethodsSetsRecentAuthError(): void {
			$request = $this->makeAuthenticatedRequest(time());

			(new StepUpAuthenticationAspect(maxAge: 300, requiredMethods: ['pwd']))->before($this->makeContext($request));

			$this->assertNotNull($request->attributes->get('recent_auth_error'));
		}

		public function testNonArrayAuthMethodsIsTreatedAsEmpty(): void {
			$request = $this->makeAuthenticatedRequest(time(), 'pwd');

			(new StepUpAuthenticationAspect(maxAge: 300, requiredMethods: ['pwd']))->before($this->makeContext($request));

			$this->assertNotNull($request->attributes->get('recent_auth_error'));
		}

		public function testStaleAuthTimeWithMatchingMethodStillFails(): void {
			// Both checks must pass — a strong method doesn't excuse an old proof
			$request = $this->makeAuthenticatedRequest(time() - 301, ['pwd']);

			(new StepUpAuthenticationAspect(maxAge: 300, requiredMethods: ['pwd']))->before($this->makeContext($request));

			$this->assertNotNull($request->attributes->get('recent_auth_error'));
		}

		// =========================================================================
		// Exception mode
		// =========================================================================

		public function testStaleAuthTimeThrowsInExceptionMode(): void {
			$request = $this->makeAuthenticatedRequest(time() - 301);

			$this->expectException(StaleAuthenticationException::class);
			(new StepUpAuthenticationAspect(maxAge: 300, throwOnFailure: true))->before($this->makeContext($request));
		}

		public function testExceptionCarriesMaxAge(): void {
			$request = $this->makeAuthenticatedRequest(null);

			try {
				(new StepUpAuthenticationAspect(maxAge: 300, throwOnFailure: true))->before($this->makeContext($request));
				$this->fail('Expected StaleAuthenticationException was not thrown.');
			} catch (StaleAuthenticationException $e) {
				$this->assertSame(300, $e->getMaxAge());
			}
		}

		public function testExceptionCarriesRequiredAndPresentedMethods(): void {
			$request = $this->makeAuthenticatedRequest(time(), ['refresh']);

			try {
				(new StepUpAuthenticationAspect(maxAge: 300, requiredMethods: ['pwd'], throwOnFailure: true))
					->before($this->makeContext($request));
				$this->fail('Expected StaleAuthenticationException was not thrown.');
			} catch (StaleAuthenticationException $e) {
				$this->assertSame(['pwd'], $e->getRequiredMethods());
				$this->assertSame(['refresh'], $e->getPresentedMethods());
			}
		}

		public function testExceptionCarriesOriginalRequestMethodAndPath(): void {
			$request = $this->makeAuthenticatedRequest(null);

			try {
				(new StepUpAuthenticationAspect(maxAge: 300, throwOnFailure: true))->before($this->makeContext($request));
				$this->fail('Expected StaleAuthenticationException was not thrown.');
			} catch (StaleAuthenticationException $e) {
				$this->assertSame('POST', $e->getRequestMethod());
				$this->assertSame('/account/delete', $e->getRequestPath());
			}
		}

		public function testFreshAuthDoesNotThrowInExceptionMode(): void {
			$request = $this->makeAuthenticatedRequest(time());

			$result = (new StepUpAuthenticationAspect(maxAge: 300, throwOnFailure: true))->before($this->makeContext($request));

			$this->assertNull($result);
		}
	}
