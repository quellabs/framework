<?php

	namespace App\Controllers;

	use App\Contracts\PasswordResetNotifierInterface;
	use App\Entities\PasswordResetTokenEntity;
	use App\Entities\UserEntity;
	use App\Exceptions\UserCreationException;
	use App\Notifiers\LogPasswordResetNotifier;
	use Doctrine\DBAL\Exception as DBALException;
	use Doctrine\ORM\EntityManagerInterface;
	use Quellabs\Canvas\Annotations\Route;
	use Quellabs\DependencyInjection\Container;
	use Symfony\Component\HttpFoundation\Request;
	use Symfony\Component\HttpFoundation\Response;
	use Quellabs\Canvas\Annotations\InterceptWith;
	use Quellabs\Canvas\Controllers\BaseController;
	use Symfony\Component\HttpFoundation\RedirectResponse;
	use Quellabs\Contracts\Templates\TemplateRenderException;

	class AuthenticationController extends BaseController {

		/**
		 * How long a password reset token remains valid after issuance.
		 */
		private const int RESET_TOKEN_TTL_SECONDS = 3600;

		/**
		 * @var EntityManagerInterface
		 */
		private EntityManagerInterface $entityManager;

		/**
		 * @var PasswordResetNotifierInterface
		 */
		private PasswordResetNotifierInterface $passwordResetNotifier;

		/**
		 * AuthenticationController constructor
		 *
		 * $entityManager and $passwordResetNotifier are resolved through the DI
		 * container like any other constructor dependency: register a service
		 * provider that supports PasswordResetNotifierInterface (see
		 * LogPasswordResetNotifier's docblock) to swap the delivery mechanism
		 * without editing this class. Falls back to LogPasswordResetNotifier
		 * when nothing is registered, so the forgot-password flow still works
		 * out of the box.
		 * @param Container $container
		 * @param EntityManagerInterface $entityManager
		 * @param PasswordResetNotifierInterface|null $passwordResetNotifier
		 */
		public function __construct(
			Container $container, EntityManagerInterface $entityManager,
			?PasswordResetNotifierInterface $passwordResetNotifier = null
		) {
			parent::__construct($container);
			$this->entityManager = $entityManager;
			$this->passwordResetNotifier = $passwordResetNotifier ?? new LogPasswordResetNotifier();
		}

		/**
		 * Display the login form
		 * @Route("/login", methods={"GET"})
		 * @InterceptWith(Quellabs\Canvas\Security\CsrfProtectionAspect::class)
		 * @param Request $request
		 * @return Response
		 * @throws TemplateRenderException
		 */
		#[Route("/login", methods: ["GET"])]
		#[InterceptWith(\Quellabs\Canvas\Security\CsrfProtectionAspect::class)]
		public function login(Request $request): Response {
			if (!empty($request->getSession()->get('auth_user_id'))) {
				return new RedirectResponse('/');
			}

			return $this->renderWithCsrf('login.{{ template_ext }}', [
				'errors' => []
			], $request);
		}

		/**
		 * Log out the current user
		 * @Route("/logout", methods={"POST", "GET"})
		 * @param Request $request
		 * @return Response
		 */
		#[Route("/logout", methods: ["POST", "GET"])]
		public function logout(Request $request): Response {
			$session = $request->getSession();

			// invalidate() no-ops without a started session, so a logout
			// with no prior session read would otherwise leave the old id live
			if (!$session->isStarted()) {
				$session->start();
			}

			$session->invalidate();
			return new RedirectResponse('/');
		}

		/**
		 * Display the registration form
		 * @Route("/register", methods={"GET"})
		 * @InterceptWith(Quellabs\Canvas\Security\CsrfProtectionAspect::class)
		 * @param Request $request
		 * @return Response
		 * @throws TemplateRenderException
		 */
		#[Route("/register", methods: ["GET"])]
		#[InterceptWith(\Quellabs\Canvas\Security\CsrfProtectionAspect::class)]
		public function registration(Request $request): Response {
			return $this->renderWithCsrf('registration_form.{{ template_ext }}', [], $request);
		}

		/**
		 * Process login form submission
		 * @Route("/login", methods={"POST"})
		 * @InterceptWith(Quellabs\Canvas\Security\RateLimitAspect::class, limit=10, window=900, scope="ip")
		 * @InterceptWith(Quellabs\Canvas\Security\CsrfProtectionAspect::class)
		 * @InterceptWith(Quellabs\Canvas\Validation\ValidateAspect::class, validator=App\Validation\LoginFormValidator::class)
		 * @param Request $request
		 * @return Response
		 * @throws TemplateRenderException
		 */
		#[Route("/login", methods: ["POST"])]
		#[InterceptWith(\Quellabs\Canvas\Security\RateLimitAspect::class, limit: 10, window: 900, scope: 'ip')]
		#[InterceptWith(\Quellabs\Canvas\Security\CsrfProtectionAspect::class)]
		#[InterceptWith(\Quellabs\Canvas\Validation\ValidateAspect::class, validator: \App\Validation\LoginFormValidator::class)]
		public function processLogin(Request $request): Response {
			// Checked before CSRF/validation: an attacker hammering this endpoint
			// shouldn't cost a fresh CSRF token or a validator run each time
			if ($request->attributes->get('rate_limit_exceeded', false)) {
				return $this->renderWithCsrf('login.{{ template_ext }}', [
					'errors' => ['general' => ['Too many attempts. Please try again later.']]
				], $request);
			}

			if (!$request->attributes->get('csrf_validation_succeeded', true)) {
				return $this->renderWithCsrf('login.{{ template_ext }}', [
					'errors' => ['general' => ['Your session has expired. Please try again.']]
				], $request);
			}

			if (!$request->attributes->get('validation_passed', true)) {
				return $this->renderWithCsrf('login.{{ template_ext }}', [
					'errors' => $request->attributes->get('validation_errors', [])
				], $request);
			}

			$username = $request->request->get('username');
			$password = $request->request->get('password');
			$user = $this->findUser($username);

			if (!$user || !$this->checkPassword($password, $user)) {
				// Generic message: don't reveal whether username or password was wrong
				return $this->renderWithCsrf('login.{{ template_ext }}', [
					'errors' => [
						'general' => ['Invalid username or password.']
					]
				], $request);
			}

			// Regenerate the session id before authenticating it, so a
			// pre-login session id fixed by an attacker can't be reused
			$session = $request->getSession();
			$session->migrate(true);
			$session->set('auth_user_id', $user->getId());

			// Lets StepUpAuthenticationAspect require a recent login elsewhere in the app
			$session->set('auth_time', time());
			$session->set('auth_methods', ['pwd']);

			return new RedirectResponse('/');
		}

		/**
		 * Process registration form submission
		 * @Route("/register", methods={"POST"})
		 * @InterceptWith(Quellabs\Canvas\Security\RateLimitAspect::class, limit=5, window=3600, scope="ip")
		 * @InterceptWith(Quellabs\Canvas\Security\CsrfProtectionAspect::class)
		 * @InterceptWith(Quellabs\Canvas\Validation\ValidateAspect::class, validator=App\Validation\RegistrationFormValidator::class)
		 * @param Request $request
		 * @return Response
		 * @throws TemplateRenderException
		 */
		#[Route("/register", methods: ["POST"])]
		#[InterceptWith(\Quellabs\Canvas\Security\RateLimitAspect::class, limit: 5, window: 3600, scope: 'ip')]
		#[InterceptWith(\Quellabs\Canvas\Security\CsrfProtectionAspect::class)]
		#[InterceptWith(\Quellabs\Canvas\Validation\ValidateAspect::class, validator: \App\Validation\RegistrationFormValidator::class)]
		public function processRegistration(Request $request): Response {
			// Keeps bot account creation from burning a CSRF token or a validator
			// run before being rejected
			if ($request->attributes->get('rate_limit_exceeded', false)) {
				return $this->renderWithCsrf('registration_form.{{ template_ext }}', [
					'errors' => ['general' => ['Too many attempts. Please try again later.']]
				], $request);
			}

			if (!$request->attributes->get('csrf_validation_succeeded', true)) {
				return $this->renderWithCsrf('registration_form.{{ template_ext }}', [
					'errors' => ['general' => ['Your session has expired. Please try again.']]
				], $request);
			}

			if (!$request->attributes->get('validation_passed', true)) {
				return $this->renderWithCsrf('registration_form.{{ template_ext }}', [
					'errors' => $request->attributes->get('validation_errors', [])
				], $request);
			}

			$name = $request->request->get('name');
			$username = $request->request->get('username');
			$password = $request->request->get('password');
			$confirmPassword = $request->request->get('confirm_password');

			if ($password !== $confirmPassword) {
				return $this->renderWithCsrf('registration_form.{{ template_ext }}', [
					'errors' => [
						'general' => ['Passwords do not match.']
					]
				], $request);
			}

			// Unlike processLogin(), this confirms whether the account exists:
			// registration has to tell a real user their username is taken,
			// and enumeration here is lower-value than via login. Swap for a
			// generic message if your threat model needs it covered too.
			$user = $this->findUser($username);

			if ($user) {
				return $this->renderWithCsrf('registration_form.{{ template_ext }}', [
					'errors' => [
						'general' => ['User already exists.']
					]
				], $request);
			}

			try {
				$user = $this->createUser($name, $username, $password);

				// Same session-fixation and auth_time/auth_methods handling as processLogin()
				$session = $request->getSession();
				$session->migrate(true);
				$session->set('auth_user_id', $user->getId());
				$session->set('auth_time', time());
				$session->set('auth_methods', ['pwd']);

				return new RedirectResponse('/');
			} catch (UserCreationException $e) {
				return $this->renderWithCsrf('registration_form.{{ template_ext }}', [
					'errors' => [
						'general' => ['Registration failed. Please try again.']
					]
				], $request);
			}
		}

		/**
		 * Display the forgot-password form
		 * @Route("/forgot-password", methods={"GET"})
		 * @InterceptWith(Quellabs\Canvas\Security\CsrfProtectionAspect::class)
		 * @param Request $request
		 * @return Response
		 * @throws TemplateRenderException
		 */
		#[Route("/forgot-password", methods: ["GET"])]
		#[InterceptWith(\Quellabs\Canvas\Security\CsrfProtectionAspect::class)]
		public function forgotPassword(Request $request): Response {
			return $this->renderWithCsrf('forgot_password.{{ template_ext }}', [
				'errors'    => [],
				'submitted' => false,
			], $request);
		}

		/**
		 * Issue a reset token for the account, if one matches, and hand it
		 * to the configured notifier. Always renders the same "submitted"
		 * response regardless of whether $username matched, so this
		 * endpoint can't be used to enumerate accounts.
		 * @Route("/forgot-password", methods={"POST"})
		 * @InterceptWith(Quellabs\Canvas\Security\RateLimitAspect::class, limit=5, window=3600, scope="ip")
		 * @InterceptWith(Quellabs\Canvas\Security\CsrfProtectionAspect::class)
		 * @InterceptWith(Quellabs\Canvas\Validation\ValidateAspect::class, validator=App\Validation\ForgotPasswordFormValidator::class)
		 * @param Request $request
		 * @return Response
		 * @throws TemplateRenderException
		 */
		#[Route("/forgot-password", methods: ["POST"])]
		#[InterceptWith(\Quellabs\Canvas\Security\RateLimitAspect::class, limit: 5, window: 3600, scope: 'ip')]
		#[InterceptWith(\Quellabs\Canvas\Security\CsrfProtectionAspect::class)]
		#[InterceptWith(\Quellabs\Canvas\Validation\ValidateAspect::class, validator: \App\Validation\ForgotPasswordFormValidator::class)]
		public function processForgotPassword(Request $request): Response {
			// Keeps repeated requests from flooding the notifier (email/log) or
			// burning through reset tokens before being rejected
			if ($request->attributes->get('rate_limit_exceeded', false)) {
				return $this->renderWithCsrf('forgot_password.{{ template_ext }}', [
					'errors'    => ['general' => ['Too many attempts. Please try again later.']],
					'submitted' => false,
				], $request);
			}

			if (!$request->attributes->get('csrf_validation_succeeded', true)) {
				return $this->renderWithCsrf('forgot_password.{{ template_ext }}', [
					'errors'    => ['general' => ['Your session has expired. Please try again.']],
					'submitted' => false,
				], $request);
			}

			if (!$request->attributes->get('validation_passed', true)) {
				return $this->renderWithCsrf('forgot_password.{{ template_ext }}', [
					'errors'    => $request->attributes->get('validation_errors', []),
					'submitted' => false,
				], $request);
			}

			$username = $request->request->get('username');
			$user = $this->findUser($username);

			if ($user !== null) {
				$rawToken = bin2hex(random_bytes(32));

				$token = new PasswordResetTokenEntity();
				$token
					->setUserId($user->getId())
					->setTokenHash($this->hashResetToken($rawToken))
					->setExpiresAt(new \DateTime('+' . self::RESET_TOKEN_TTL_SECONDS . ' seconds'));

				$this->entityManager->persist($token);
				$this->entityManager->flush();

				$this->passwordResetNotifier->send($user->getUsername(), $rawToken);
			}

			return $this->renderWithCsrf('forgot_password.{{ template_ext }}', [
				'errors'    => [],
				'submitted' => true,
			], $request);
		}

		/**
		 * Display the reset-password form for the token in the query string.
		 * Token validity isn't checked here — processResetPassword() does
		 * that on submit, so an expired token still shows a clear error.
		 * @Route("/reset-password", methods={"GET"})
		 * @InterceptWith(Quellabs\Canvas\Security\CsrfProtectionAspect::class)
		 * @param Request $request
		 * @return Response
		 * @throws TemplateRenderException
		 */
		#[Route("/reset-password", methods: ["GET"])]
		#[InterceptWith(\Quellabs\Canvas\Security\CsrfProtectionAspect::class)]
		public function resetPassword(Request $request): Response {
			return $this->renderWithCsrf('reset_password.{{ template_ext }}', [
				'token'  => (string)$request->query->get('token', ''),
				'errors' => [],
			], $request);
		}

		/**
		 * Consume the token and, if still valid, set the new password. On
		 * success, also marks every other outstanding token for the account
		 * as used, ending every reset link in flight, not just this one.
		 * @Route("/reset-password", methods={"POST"})
		 * @InterceptWith(Quellabs\Canvas\Security\RateLimitAspect::class, limit=10, window=900, scope="ip")
		 * @InterceptWith(Quellabs\Canvas\Security\CsrfProtectionAspect::class)
		 * @InterceptWith(Quellabs\Canvas\Validation\ValidateAspect::class, validator=App\Validation\ResetPasswordFormValidator::class)
		 * @param Request $request
		 * @return Response
		 * @throws TemplateRenderException
		 */
		#[Route("/reset-password", methods: ["POST"])]
		#[InterceptWith(\Quellabs\Canvas\Security\RateLimitAspect::class, limit: 10, window: 900, scope: 'ip')]
		#[InterceptWith(\Quellabs\Canvas\Security\CsrfProtectionAspect::class)]
		#[InterceptWith(\Quellabs\Canvas\Validation\ValidateAspect::class, validator: \App\Validation\ResetPasswordFormValidator::class)]
		public function processResetPassword(Request $request): Response {
			$rawToken = (string)$request->request->get('token', '');

			// Throttles guessing at the (unused) raw token value itself before
			// doing any CSRF/validation work
			if ($request->attributes->get('rate_limit_exceeded', false)) {
				return $this->renderWithCsrf('reset_password.{{ template_ext }}', [
					'token'  => $rawToken,
					'errors' => ['general' => ['Too many attempts. Please try again later.']],
				], $request);
			}

			if (!$request->attributes->get('csrf_validation_succeeded', true)) {
				return $this->renderWithCsrf('reset_password.{{ template_ext }}', [
					'token'  => $rawToken,
					'errors' => ['general' => ['Your session has expired. Please try again.']],
				], $request);
			}

			if (!$request->attributes->get('validation_passed', true)) {
				return $this->renderWithCsrf('reset_password.{{ template_ext }}', [
					'token'  => $rawToken,
					'errors' => $request->attributes->get('validation_errors', []),
				], $request);
			}

			$password = $request->request->get('password');
			$confirmPassword = $request->request->get('confirm_password');

			if ($password !== $confirmPassword) {
				return $this->renderWithCsrf('reset_password.{{ template_ext }}', [
					'token'  => $rawToken,
					'errors' => ['general' => ['Passwords do not match.']],
				], $request);
			}

			$resetToken = $this->findValidResetToken($rawToken);

			if ($resetToken === null) {
				return $this->renderWithCsrf('reset_password.{{ template_ext }}', [
					'token'  => $rawToken,
					'errors' => ['general' => ['This password reset link is invalid or has expired.']],
				], $request);
			}

			$user = $this->entityManager->find(UserEntity::class, $resetToken->getUserId());

			if ($user === null) {
				return $this->renderWithCsrf('reset_password.{{ template_ext }}', [
					'token'  => $rawToken,
					'errors' => ['general' => ['This password reset link is invalid or has expired.']],
				], $request);
			}

			$user->setPassword(password_hash($password, PASSWORD_DEFAULT));

			$now = new \DateTime();
			$resetToken->setUsedAt($now);

			$otherTokens = $this->entityManager->getRepository(PasswordResetTokenEntity::class)->findBy(['userId' => $user->getId()]);

			foreach ($otherTokens as $otherToken) {
				if ($otherToken->getUsedAt() === null) {
					$otherToken->setUsedAt($now);
				}
			}

			$this->entityManager->flush();

			return new RedirectResponse('/login');
		}

		/**
		 * Renders a template with the current request's CSRF token merged in,
		 * so every form in this controller can embed it without each call site
		 * repeating the attribute lookup. CsrfProtectionAspect (declared on
		 * every route in this controller) always publishes a fresh token here,
		 * whether or not CSRF validation itself passed.
		 * @param string $template
		 * @param array $data
		 * @param Request $request
		 * @return Response
		 * @throws TemplateRenderException
		 */
		private function renderWithCsrf(string $template, array $data, Request $request): Response {
			$data['csrf_token'] = $request->attributes->get('csrf_token');
			$data['csrf_token_name'] = $request->attributes->get('csrf_token_name', '_csrf_token');
			return $this->render($template, $data);
		}

		/**
		 * Looks up a password reset token by its raw value and returns it
		 * only when still usable: known, unused, and unexpired.
		 * @param string $rawToken As handed to PasswordResetNotifierInterface::send().
		 * @return PasswordResetTokenEntity|null
		 */
		private function findValidResetToken(string $rawToken): ?PasswordResetTokenEntity {
			$token = $this->entityManager->getRepository(PasswordResetTokenEntity::class)->findOneBy(['tokenHash' => $this->hashResetToken($rawToken)]);

			if ($token === null || $token->getUsedAt() !== null || $token->getExpiresAt() < new \DateTime()) {
				return null;
			}

			return $token;
		}

		/**
		 * @param string $rawToken
		 * @return string
		 */
		private function hashResetToken(string $rawToken): string {
			return hash('sha256', $rawToken);
		}

		/**
		 * Find user by username in database
		 * @param string $username
		 * @return UserEntity|null
		 */
		private function findUser(string $username): ?UserEntity {
			return $this->entityManager->getRepository(UserEntity::class)->findOneBy(['username' => $username]);
		}

		/**
		 * Verify password against user's stored hash
		 * @param string $password
		 * @param UserEntity $user
		 * @return bool
		 */
		private function checkPassword(string $password, UserEntity $user): bool {
			return password_verify($password, $user->getPassword());
		}

		/**
		 * Create a new user and persist to database
		 * @param string $name
		 * @param string $username
		 * @param string $password
		 * @return UserEntity
		 * @throws UserCreationException
		 */
		private function createUser(string $name, string $username, string $password): UserEntity {
			try {
				$user = new UserEntity();
				$user->setName($name);
				$user->setUsername($username);
				$user->setPassword(password_hash($password, PASSWORD_DEFAULT));

				$this->entityManager->persist($user);
				$this->entityManager->flush();

				return $user;
			} catch (DBALException $e) {
				error_log("User creation failed: " . $e->getMessage());
				throw new UserCreationException("Failed to create user account", 0, $e);
			}
		}
	}
