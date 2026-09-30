<?php

	namespace Quellabs\Canvas\Tests\Security;

	use PHPUnit\Framework\TestCase;
	use Quellabs\Canvas\Exceptions\SessionAuthenticationException;
	use Quellabs\Canvas\Routing\Contracts\MethodContextInterface;
	use Quellabs\Canvas\Security\SessionAuthenticationAspect;
	use Symfony\Component\HttpFoundation\Request;
	use Symfony\Component\HttpFoundation\Session\Session;
	use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

	/**
	 * Unit tests for SessionAuthenticationAspect.
	 *
	 * Each test builds a Request carrying a real Symfony Session (backed by
	 * MockArraySessionStorage), pre-populates the session keys a login flow
	 * would set, and asserts on request attributes (attribute mode) or thrown
	 * exceptions (exception mode). No HTTP kernel or actual login required.
	 */
	class SessionAuthenticationAspectTest extends TestCase {

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
		 * Build a Request with a real, started Session attached.
		 * @param array<string, mixed> $sessionData Initial session key/value pairs
		 * @return Request
		 */
		private function makeRequestWithSession(array $sessionData = []): Request {
			$request = Request::create('/');
			$session = new Session(new MockArraySessionStorage());

			foreach ($sessionData as $key => $value) {
				$session->set($key, $value);
			}

			$request->setSession($session);
			return $request;
		}

		// =========================================================================
		// Attribute mode — success path
		// =========================================================================

		public function testAuthenticatedSessionSetsSessionUserId(): void {
			$request = $this->makeRequestWithSession(['user_id' => 'user-42']);

			(new SessionAuthenticationAspect())->before($this->makeContext($request));

			$this->assertSame('user-42', $request->attributes->get('session_user_id'));
		}

		public function testAuthenticatedSessionSetsAuthTimeWhenPresent(): void {
			$loginTime = time() - 60;
			$request   = $this->makeRequestWithSession(['user_id' => 'user-1', 'auth_time' => $loginTime]);

			(new SessionAuthenticationAspect())->before($this->makeContext($request));

			$this->assertSame($loginTime, $request->attributes->get('auth_time'));
		}

		public function testAuthTimeAttributeAbsentWhenNotInSession(): void {
			$request = $this->makeRequestWithSession(['user_id' => 'user-1']);

			(new SessionAuthenticationAspect())->before($this->makeContext($request));

			$this->assertFalse($request->attributes->has('auth_time'));
		}

		public function testNonNumericAuthTimeInSessionIsNotPublished(): void {
			$request = $this->makeRequestWithSession(['user_id' => 'user-1', 'auth_time' => 'not-a-timestamp']);

			(new SessionAuthenticationAspect())->before($this->makeContext($request));

			$this->assertFalse($request->attributes->has('auth_time'));
		}

		public function testAuthenticatedSessionSetsAuthMethods(): void {
			$request = $this->makeRequestWithSession(['user_id' => 'user-1', 'auth_methods' => ['pwd']]);

			(new SessionAuthenticationAspect())->before($this->makeContext($request));

			$this->assertSame(['pwd'], $request->attributes->get('auth_methods'));
		}

		public function testAuthMethodsDefaultsToEmptyArrayWhenAbsent(): void {
			$request = $this->makeRequestWithSession(['user_id' => 'user-1']);

			(new SessionAuthenticationAspect())->before($this->makeContext($request));

			$this->assertSame([], $request->attributes->get('auth_methods'));
		}

		public function testNonArrayAuthMethodsInSessionIsTreatedAsEmpty(): void {
			$request = $this->makeRequestWithSession(['user_id' => 'user-1', 'auth_methods' => 'pwd']);

			(new SessionAuthenticationAspect())->before($this->makeContext($request));

			$this->assertSame([], $request->attributes->get('auth_methods'));
		}

		public function testAuthenticatedSessionClearsStaleSessionError(): void {
			$request = $this->makeRequestWithSession(['user_id' => 'user-1']);
			$request->attributes->set('session_error', 'stale error');

			(new SessionAuthenticationAspect())->before($this->makeContext($request));

			$this->assertNull($request->attributes->get('session_error'));
		}

		public function testBeforeReturnsNullOnSuccess(): void {
			$request = $this->makeRequestWithSession(['user_id' => 'user-1']);

			$result = (new SessionAuthenticationAspect())->before($this->makeContext($request));

			$this->assertNull($result);
		}

		// =========================================================================
		// Attribute mode — failure path
		// =========================================================================

		public function testNoAuthUserIdInSessionSetsSessionError(): void {
			$request = $this->makeRequestWithSession();

			(new SessionAuthenticationAspect())->before($this->makeContext($request));

			$this->assertNotNull($request->attributes->get('session_error'));
		}

		public function testMissingAuthUserIdClearsStaleSessionUserId(): void {
			$request = $this->makeRequestWithSession();
			$request->attributes->set('session_user_id', 'old-user');

			(new SessionAuthenticationAspect())->before($this->makeContext($request));

			$this->assertNull($request->attributes->get('session_user_id'));
		}

		public function testMissingAuthUserIdClearsStaleAuthTimeAndMethods(): void {
			$request = $this->makeRequestWithSession();
			$request->attributes->set('auth_time', time());
			$request->attributes->set('auth_methods', ['pwd']);

			(new SessionAuthenticationAspect())->before($this->makeContext($request));

			$this->assertNull($request->attributes->get('auth_time'));
			$this->assertNull($request->attributes->get('auth_methods'));
		}

		public function testBeforeReturnsNullOnFailureInAttributeMode(): void {
			$request = $this->makeRequestWithSession();

			$result = (new SessionAuthenticationAspect())->before($this->makeContext($request));

			$this->assertNull($result);
		}

		// =========================================================================
		// Exception mode
		// =========================================================================

		public function testMissingSessionThrowsInExceptionMode(): void {
			$request = $this->makeRequestWithSession();

			$this->expectException(SessionAuthenticationException::class);
			(new SessionAuthenticationAspect(throwOnFailure: true))->before($this->makeContext($request));
		}

		public function testAuthenticatedSessionDoesNotThrowInExceptionMode(): void {
			$request = $this->makeRequestWithSession(['user_id' => 'user-1']);

			$result = (new SessionAuthenticationAspect(throwOnFailure: true))->before($this->makeContext($request));

			$this->assertNull($result);
		}
	}
