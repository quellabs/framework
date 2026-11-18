<?php

	namespace Quellabs\CanvasAuthorization\Tests;

	use PHPUnit\Framework\TestCase;
	use Quellabs\Contracts\AOP\MethodContextInterface;
	use Quellabs\CanvasAuthorization\Exceptions\SessionAuthenticationException;
	use Quellabs\CanvasAuthorization\SessionAuthenticationAspect;
	use Symfony\Component\HttpFoundation\Request;
	use Symfony\Component\HttpFoundation\Session\Session;
	use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

	class SessionAuthenticationAspectTest extends TestCase {

		private function requestWithSession(): Request {
			$request = Request::create('/');
			$request->setSession(new Session(new MockArraySessionStorage()));
			return $request;
		}

		private function contextFor(Request $request): MethodContextInterface {
			$context = $this->createMock(MethodContextInterface::class);
			$context->method('getRequest')->willReturn($request);
			return $context;
		}

		public function testNoSessionSetsSessionErrorAndReturnsNull(): void {
			$request = $this->requestWithSession();

			$response = (new SessionAuthenticationAspect())->before($this->contextFor($request));

			$this->assertNull($response);
			$this->assertSame('No authenticated session', $request->attributes->get('session_error'));
		}

		public function testNoSessionClearsAnyStaleAuthAttributesFromAnEarlierAspectOnTheSameRequest(): void {
			$request = $this->requestWithSession();
			$request->attributes->set('session_user_id', 99);
			$request->attributes->set('auth_time', 12345);
			$request->attributes->set('auth_methods', ['pwd']);

			(new SessionAuthenticationAspect())->before($this->contextFor($request));

			$this->assertFalse($request->attributes->has('session_user_id'));
			$this->assertFalse($request->attributes->has('auth_time'));
			$this->assertFalse($request->attributes->has('auth_methods'));
		}

		public function testNoSessionWithThrowOnFailureThrows(): void {
			$this->expectException(SessionAuthenticationException::class);

			(new SessionAuthenticationAspect(throwOnFailure: true))->before($this->contextFor($this->requestWithSession()));
		}

		public function testAuthenticatedSessionPublishesSessionUserIdAndReturnsNull(): void {
			$request = $this->requestWithSession();
			$request->getSession()->set('auth_user_id', 42);

			$response = (new SessionAuthenticationAspect())->before($this->contextFor($request));

			$this->assertNull($response);
			$this->assertSame(42, $request->attributes->get('session_user_id'));
		}

		public function testAuthenticatedSessionPublishesAuthTimeAndAuthMethods(): void {
			$request = $this->requestWithSession();
			$request->getSession()->set('auth_user_id', 42);
			$request->getSession()->set('auth_time', 1700000000);
			$request->getSession()->set('auth_methods', ['pwd']);

			(new SessionAuthenticationAspect())->before($this->contextFor($request));

			$this->assertSame(1700000000, $request->attributes->get('auth_time'));
			$this->assertSame(['pwd'], $request->attributes->get('auth_methods'));
		}

		public function testAuthenticatedSessionWithoutAuthTimeLeavesTheAttributeUnset(): void {
			// StepUpAuthenticationAspect treats a missing auth_time as "never
			// recently authenticated" (is_numeric() check) — publishing it
			// as null here instead would also satisfy that check incorrectly.
			$request = $this->requestWithSession();
			$request->getSession()->set('auth_user_id', 42);

			(new SessionAuthenticationAspect())->before($this->contextFor($request));

			$this->assertFalse($request->attributes->has('auth_time'));
		}

		public function testAuthenticatedSessionWithoutAuthMethodsDefaultsToEmptyArray(): void {
			$request = $this->requestWithSession();
			$request->getSession()->set('auth_user_id', 42);

			(new SessionAuthenticationAspect())->before($this->contextFor($request));

			$this->assertSame([], $request->attributes->get('auth_methods'));
		}

		public function testNonArrayAuthMethodsInSessionIsCoercedToEmptyArray(): void {
			// A tampered or corrupted session value must not be trusted as
			// a usable auth_methods list just because the key is set.
			$request = $this->requestWithSession();
			$request->getSession()->set('auth_user_id', 42);
			$request->getSession()->set('auth_methods', 'pwd');

			(new SessionAuthenticationAspect())->before($this->contextFor($request));

			$this->assertSame([], $request->attributes->get('auth_methods'));
		}

		public function testAuthenticatedSessionClearsAnyStaleSessionErrorFromAnEarlierAttemptOnTheSameRequest(): void {
			$request = $this->requestWithSession();
			$request->getSession()->set('auth_user_id', 42);
			$request->attributes->set('session_error', 'No authenticated session');

			(new SessionAuthenticationAspect())->before($this->contextFor($request));

			$this->assertFalse($request->attributes->has('session_error'));
		}
	}
