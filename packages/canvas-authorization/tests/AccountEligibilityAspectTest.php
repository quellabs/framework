<?php

	namespace Quellabs\CanvasAuthorization\Tests;

	use PHPUnit\Framework\TestCase;
	use Quellabs\Contracts\AOP\MethodContextInterface;
	use Quellabs\CanvasAuthorization\AccountEligibilityAspect;
	use Quellabs\CanvasAuthorization\Exceptions\AccountEligibilityException;
	use Quellabs\CanvasAuthorization\Tests\Fixtures\FixtureEligibleUser;
	use Quellabs\ObjectQuel\EntityManager;
	use Symfony\Component\HttpFoundation\Request;
	use Symfony\Component\HttpFoundation\Session\Session;
	use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

	class AccountEligibilityAspectTest extends TestCase {

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

		public function testConstructorRejectsAUserEntityClassThatDoesNotImplementTheInterface(): void {
			$this->expectException(\InvalidArgumentException::class);

			new AccountEligibilityAspect(\stdClass::class);
		}

		public function testNoSessionUserIdAttributeFailsWithoutHittingTheDatabase(): void {
			// SessionAuthenticationAspect (run earlier in the chain) is what
			// publishes session_user_id — its absence means there's no
			// logged-in session at all, so this must fail before even
			// trying a database lookup.
			$entityManager = $this->createMock(EntityManager::class);
			$entityManager->expects($this->never())->method('find');

			$aspect = new AccountEligibilityAspect(FixtureEligibleUser::class, entityManager: $entityManager);
			$response = $aspect->before($this->contextFor($this->requestWithSession()));

			$this->assertNull($response);
		}

		public function testNoSessionUserIdAttributeSetsAccountEligibilityErrorAttribute(): void {
			$aspect = new AccountEligibilityAspect(FixtureEligibleUser::class, entityManager: $this->createMock(EntityManager::class));

			$request = $this->requestWithSession();
			$aspect->before($this->contextFor($request));

			$this->assertSame('No authenticated session', $request->attributes->get('account_eligibility_error'));
		}

		public function testNoSessionUserIdAttributeWithThrowOnFailureThrows(): void {
			$this->expectException(AccountEligibilityException::class);

			$aspect = new AccountEligibilityAspect(
				FixtureEligibleUser::class,
				throwOnFailure: true,
				entityManager: $this->createMock(EntityManager::class),
			);

			$aspect->before($this->contextFor($this->requestWithSession()));
		}

		public function testWithinTheValidationIntervalSkipsTheDatabaseLookup(): void {
			$entityManager = $this->createMock(EntityManager::class);
			$entityManager->expects($this->never())->method('find');

			$request = $this->requestWithSession();
			$request->attributes->set('session_user_id', 7);
			$request->getSession()->set('user_validated_at', time());

			$aspect = new AccountEligibilityAspect(FixtureEligibleUser::class, validationInterval: 300, entityManager: $entityManager);
			$response = $aspect->before($this->contextFor($request));

			$this->assertNull($response);
			$this->assertFalse($request->attributes->has('account_eligibility_error'));
		}

		public function testPastTheValidationIntervalRevalidatesAnEligibleUserAndUpdatesTheTimestamp(): void {
			$entityManager = $this->createMock(EntityManager::class);
			$entityManager->expects($this->once())
				->method('find')
				->with(FixtureEligibleUser::class, 7)
				->willReturn(new FixtureEligibleUser(banned: false));

			$request = $this->requestWithSession();
			$request->attributes->set('session_user_id', 7);
			$request->getSession()->set('user_validated_at', time() - 301);

			$aspect = new AccountEligibilityAspect(FixtureEligibleUser::class, validationInterval: 300, entityManager: $entityManager);
			$response = $aspect->before($this->contextFor($request));

			$this->assertNull($response);
			$this->assertFalse($request->attributes->has('account_eligibility_error'));
			$this->assertGreaterThan(time() - 5, $request->getSession()->get('user_validated_at'));
		}

		public function testNeverValidatedBeforeAlwaysHitsTheDatabase(): void {
			// user_validated_at defaults to 0 when absent, so a session
			// that was never through this aspect's "revalidated" branch
			// is checked on its very first eligible request too.
			$entityManager = $this->createMock(EntityManager::class);
			$entityManager->expects($this->once())
				->method('find')
				->willReturn(new FixtureEligibleUser(banned: false));

			$request = $this->requestWithSession();
			$request->attributes->set('session_user_id', 7);

			$aspect = new AccountEligibilityAspect(FixtureEligibleUser::class, entityManager: $entityManager);
			$aspect->before($this->contextFor($request));
		}

		public function testBannedUserClearsTheSessionAndFails(): void {
			$entityManager = $this->createMock(EntityManager::class);
			$entityManager->method('find')->willReturn(new FixtureEligibleUser(banned: true));

			$request = $this->requestWithSession();
			$request->attributes->set('session_user_id', 7);
			$request->getSession()->set('auth_user_id', 7);
			$request->getSession()->set('auth_time', time());
			$request->getSession()->set('auth_methods', ['pwd']);
			$request->getSession()->set('user_validated_at', time() - 301);

			$aspect = new AccountEligibilityAspect(FixtureEligibleUser::class, validationInterval: 300, entityManager: $entityManager);
			$response = $aspect->before($this->contextFor($request));

			$this->assertNull($response);
			$this->assertSame('Account is no longer eligible', $request->attributes->get('account_eligibility_error'));
			$this->assertFalse($request->getSession()->has('auth_user_id'));
			$this->assertFalse($request->getSession()->has('auth_time'));
			$this->assertFalse($request->getSession()->has('auth_methods'));
			$this->assertFalse($request->getSession()->has('user_validated_at'));
		}

		public function testDeletedUserClearsTheSessionAndFails(): void {
			// find() returning null (the row no longer exists) must be
			// treated the same as an explicit ban, not as "skip the check".
			$entityManager = $this->createMock(EntityManager::class);
			$entityManager->method('find')->willReturn(null);

			$request = $this->requestWithSession();
			$request->attributes->set('session_user_id', 7);
			$request->getSession()->set('auth_user_id', 7);
			$request->getSession()->set('user_validated_at', time() - 301);

			$aspect = new AccountEligibilityAspect(FixtureEligibleUser::class, validationInterval: 300, entityManager: $entityManager);
			$aspect->before($this->contextFor($request));

			$this->assertFalse($request->getSession()->has('auth_user_id'));
		}

		public function testBannedUserWithThrowOnFailureThrows(): void {
			$this->expectException(AccountEligibilityException::class);

			$entityManager = $this->createMock(EntityManager::class);
			$entityManager->method('find')->willReturn(new FixtureEligibleUser(banned: true));

			$request = $this->requestWithSession();
			$request->attributes->set('session_user_id', 7);

			$aspect = new AccountEligibilityAspect(
				FixtureEligibleUser::class,
				validationInterval: 300,
				throwOnFailure: true,
				entityManager: $entityManager,
			);

			$aspect->before($this->contextFor($request));
		}

		public function testEligibleUserClearsAnyStaleAccountEligibilityErrorFromAnEarlierAttemptOnTheSameRequest(): void {
			$entityManager = $this->createMock(EntityManager::class);
			$entityManager->method('find')->willReturn(new FixtureEligibleUser(banned: false));

			$request = $this->requestWithSession();
			$request->attributes->set('session_user_id', 7);
			$request->attributes->set('account_eligibility_error', 'Account is no longer eligible');

			$aspect = new AccountEligibilityAspect(FixtureEligibleUser::class, entityManager: $entityManager);
			$aspect->before($this->contextFor($request));

			$this->assertFalse($request->attributes->has('account_eligibility_error'));
		}
	}
