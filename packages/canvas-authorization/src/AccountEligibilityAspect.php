<?php

	namespace Quellabs\CanvasAuthorization;

	use Quellabs\ObjectQuel\Exception\QuelException;
	use Quellabs\Contracts\AOP\BeforeAspectInterface;
	use Quellabs\ObjectQuel\Exception\EntityResolutionException;
	use Quellabs\Contracts\AOP\MethodContextInterface;
	use Quellabs\CanvasAuthorization\Contracts\AccountEligibilityInterface;
	use Quellabs\CanvasAuthorization\Exceptions\AccountEligibilityException;
	use Quellabs\ObjectQuel\EntityManager;
	use Symfony\Component\HttpFoundation\Request;
	use Symfony\Component\HttpFoundation\Response;

	/**
	 * Periodically re-validates the logged-in user against the database.
	 *
	 * This aspect does not perform the base session check itself — chain it
	 * after Quellabs\CanvasAuthorization\SessionAuthenticationAspect, which reads
	 * 'auth_user_id' from the session and publishes 'session_user_id' on the
	 * request. This aspect only adds the database-backed, app-specific part:
	 * confirming the user hasn't been banned or deleted since login, at most
	 * once per $validationInterval to avoid a database hit on every request.
	 *
	 * Like every other authentication aspect in this ecosystem, it never
	 * builds a Response itself — it only sets a request attribute or throws:
	 *
	 * - Attribute mode (default): on failure, sets 'account_eligibility_error'
	 *   on $request->attributes and returns null, letting the controller decide
	 *   how to respond.
	 *
	 * - Exception mode (throwOnFailure=true): throws AccountEligibilityException,
	 *   which propagates to the kernel's error handler. install:auth scaffolds
	 *   an AuthErrorHandler (src/Errors/AuthErrorHandler.php) that turns this,
	 *   along with SessionAuthenticationException, into a redirect to the login
	 *   page — that's what actually protects a page when using
	 *   AuthenticatedController, not anything built into this aspect.
	 *
	 * Unlike a scaffolded stub, this class is used directly from the package —
	 * it is not copied into the application, so it receives fixes via
	 * `composer update` rather than being frozen at generation time. Only the
	 * user entity needs to be application-owned, via $userEntityClass and
	 * AccountEligibilityInterface. An application that needs a fundamentally
	 * different revalidation strategy than "banned via an interface method"
	 * should eject a copy with `sculpt make:auth-aspect` and edit it directly.
	 *
	 * @InterceptWith(Quellabs\CanvasAuthorization\SessionAuthenticationAspect::class, throwOnFailure=true)
	 * @InterceptWith(Quellabs\CanvasAuthorization\AccountEligibilityAspect::class, userEntityClass=App\Entities\UserEntity::class, throwOnFailure=true)
	 */
	class AccountEligibilityAspect implements BeforeAspectInterface {

		/**
		 * Fully qualified class name of the application's user entity.
		 * Must implement AccountEligibilityInterface.
		 * @var class-string<AccountEligibilityInterface>
		 */
		private string $userEntityClass;

		/**
		 * Time interval (in seconds) between database re-checks of account eligibility
		 * This prevents hitting the database on every request while still ensuring
		 * banned or deleted users are eventually logged out
		 * @var int
		 */
		private int $validationInterval;

		/**
		 * If true, throws AccountEligibilityException instead of writing to request attributes
		 * @var bool
		 */
		private bool $throwOnFailure;

		/**
		 * ObjectQuel EntityManager for database operations
		 * Used to fetch and validate user entities from the database
		 * @var EntityManager
		 */
		private EntityManager $entityManager;

		/**
		 * Constructor to initialize the account eligibility aspect
		 * @param class-string<AccountEligibilityInterface> $userEntityClass The application's user entity class, must implement AccountEligibilityInterface
		 * @param int $validationInterval Time in seconds between database validations (defaults to 300 = 5 minutes)
		 * @param bool $throwOnFailure If true, throws AccountEligibilityException instead of writing to request attributes
		 * @param EntityManager|null $entityManager The entity manager for database operations
		 */
		public function __construct(
			string $userEntityClass,
			int    $validationInterval = 300,
			bool   $throwOnFailure = false,
			?EntityManager $entityManager = null
		) {
			// Fail at construction rather than on first request — a class that doesn't
			// implement the interface would only surface as an unexplained fatal error
			// deep inside before() the first time a user is actually re-validated
			if (!is_subclass_of($userEntityClass, AccountEligibilityInterface::class)) {
				throw new \InvalidArgumentException(
					"userEntityClass '{$userEntityClass}' must implement " . AccountEligibilityInterface::class . "."
				);
			}

			// Same reasoning as the check above: an EntityManager that fails to resolve
			// via DI should fail loudly here, not as a fatal error the first time
			// before() actually tries to revalidate a user
			if ($entityManager === null) {
				throw new \InvalidArgumentException('An EntityManager instance is required.');
			}

			$this->userEntityClass = $userEntityClass;
			$this->validationInterval = $validationInterval;
			$this->throwOnFailure = $throwOnFailure;
			$this->entityManager = $entityManager;
		}
		
		/**
		 * Check session eligibility and periodically re-validate the user in the database.
		 *
		 * On failure in attribute mode: sets 'account_eligibility_error' on $request->attributes, returns null.
		 * On failure in exception mode: throws AccountEligibilityException.
		 *
		 * @param MethodContextInterface $context The context containing request and method information
		 * @return Response|null Always null — this aspect never short-circuits via Response.
		 * @throws EntityResolutionException
		 * @throws QuelException
		 */
		public function before(MethodContextInterface $context): ?Response {
			$request = $context->getRequest();

			// SessionAuthenticationAspect (run earlier in the chain) publishes this
			// attribute only when 'auth_user_id' is present in the session; its
			// absence means there is no logged-in session at all
			$userId = $request->attributes->get('session_user_id');

			if ($userId === null) {
				return $this->fail($request, 'No authenticated session');
			}

			// Only hit the database if we haven't validated recently, to avoid a
			// query on every single request from an already-checked-out user
			$session = $request->getSession();
			$lastValidated = $session->get('user_validated_at', 0);
			$currentTime = time();

			if ($currentTime - $lastValidated > $this->validationInterval) {
				/** @var AccountEligibilityInterface|null $user */
				$user = $this->entityManager->find($this->userEntityClass, $userId);

				if (!$user || $user->isBanned()) {
					// User no longer exists or has been banned - clear the session
					$session->remove('auth_user_id');
					$session->remove('auth_time');
					$session->remove('auth_methods');
					$session->remove('user_validated_at');
					return $this->fail($request, 'Account is no longer eligible');
				}

				// User is valid - update the validation timestamp to avoid immediate re-validation
				$session->set('user_validated_at', $currentTime);
			}

			$request->attributes->remove('account_eligibility_error');
			return null;
		}
		
		/**
		 * Report an eligibility failure via the configured failure mode.
		 * @param Request $request
		 * @param string $reason Reason the account is not eligible
		 * @return Response|null Always null — attribute mode never short-circuits via Response.
		 */
		private function fail(Request $request, string $reason): ?Response {
			if ($this->throwOnFailure) {
				throw new AccountEligibilityException($reason);
			}

			$request->attributes->set('account_eligibility_error', $reason);
			return null;
		}
	}
