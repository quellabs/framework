<?php
	
	namespace App\Controllers;
	
	use App\Contracts\PasswordResetNotifierInterface;
	use App\Entities\PasswordResetTokenEntity;
	use App\Entities\UserEntity;
	use App\Exceptions\UserCreationException;
	use App\Notifiers\LogPasswordResetNotifier;
	use Quellabs\Canvas\Annotations\Route;
	use Quellabs\DependencyInjection\Container;
	use Quellabs\ObjectQuel\ObjectQuel\QuelException;
	use Quellabs\ObjectQuel\OrmException;
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
		private const RESET_TOKEN_TTL_SECONDS = 3600;

		/**
		 * @var PasswordResetNotifierInterface
		 */
		private PasswordResetNotifierInterface $passwordResetNotifier;

		/**
		 * AuthenticationController constructor
		 *
		 * $passwordResetNotifier is resolved through the DI container like any
		 * other constructor dependency: register a service provider that
		 * supports PasswordResetNotifierInterface (see LogPasswordResetNotifier's
		 * docblock) to swap the delivery mechanism without editing this class.
		 * Falls back to LogPasswordResetNotifier when nothing is registered, so
		 * the forgot-password flow still works out of the box.
		 * @param Container $container
		 * @param PasswordResetNotifierInterface|null $passwordResetNotifier
		 */
		public function __construct(Container $container, ?PasswordResetNotifierInterface $passwordResetNotifier = null) {
			parent::__construct($container);
			$this->passwordResetNotifier = $passwordResetNotifier ?? new LogPasswordResetNotifier();
		}

		/**
		 * Display the login form
		 * @Route("/login", methods={"GET"})
		 * @param Request $request
		 * @return Response
		 * @throws TemplateRenderException
		 */
		public function login(Request $request): Response {
			if (!empty($request->getSession()->get('auth_user_id'))) {
				return new RedirectResponse('/');
			}
			
			return $this->render('login.{{ template_ext }}', [
				'errors' => []
			]);
		}
		
		/**
		 * Log out the current user
		 * @Route("/logout", methods={"POST", "GET"})
		 * @param Request $request
		 * @return Response
		 */
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
		 * @return Response
		 * @throws TemplateRenderException
		 */
		public function registration(): Response {
			return $this->render('registration_form.{{ template_ext }}');
		}
		
		/**
		 * Process login form submission
		 * @Route("/login", methods={"POST"})
		 * @InterceptWith(Quellabs\Canvas\Validation\ValidateAspect::class, validator=App\Validation\LoginFormValidator::class)
		 * @param Request $request
		 * @return Response
		 * @throws TemplateRenderException
		 */
		public function processLogin(Request $request): Response {
			if (!$request->attributes->get('validation_passed', true)) {
				return $this->render('login.{{ template_ext }}', [
					'errors' => $request->attributes->get('validation_errors', [])
				]);
			}

			$username = $request->request->get('username');
			$password = $request->request->get('password');
			$user = $this->findUser($username);

			if (!$user || !$this->checkPassword($password, $user)) {
				// Generic message: don't reveal whether username or password was wrong
				return $this->render('login.{{ template_ext }}', [
					'errors' => [
						'general' => ['Invalid username or password.']
					]
				]);
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
		 * @InterceptWith(Quellabs\Canvas\Validation\ValidateAspect::class, validator=App\Validation\RegistrationFormValidator::class)
		 * @param Request $request
		 * @return Response
		 * @throws TemplateRenderException|OrmException
		 */
		public function processRegistration(Request $request): Response {
			if (!$request->attributes->get('validation_passed', true)) {
				return $this->render('registration_form.{{ template_ext }}', [
					'errors' => $request->attributes->get('validation_errors', [])
				]);
			}

			$name = $request->request->get('name');
			$username = $request->request->get('username');
			$password = $request->request->get('password');
			$confirmPassword = $request->request->get('confirm_password');

			if ($password !== $confirmPassword) {
				return $this->render('registration_form.{{ template_ext }}', [
					'errors' => [
						'general' => ['Passwords do not match.']
					]
				]);
			}

			// Unlike processLogin(), this confirms whether the account exists:
			// registration has to tell a real user their username is taken,
			// and enumeration here is lower-value than via login. Swap for a
			// generic message if your threat model needs it covered too.
			$user = $this->findUser($username);

			if ($user) {
				return $this->render('registration_form.{{ template_ext }}', [
					'errors' => [
						'general' => ['User already exists.']
					]
				]);
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
				return $this->render('registration_form.{{ template_ext }}', [
					'errors' => [
						'general' => ['Registration failed. Please try again.']
					]
				]);
			}
		}

		/**
		 * Display the forgot-password form
		 * @Route("/forgot-password", methods={"GET"})
		 * @return Response
		 * @throws TemplateRenderException
		 */
		public function forgotPassword(): Response {
			return $this->render('forgot_password.{{ template_ext }}', [
				'errors'    => [],
				'submitted' => false,
			]);
		}

		/**
		 * Issue a reset token for the account, if one matches, and hand it
		 * to the configured notifier. Always renders the same "submitted"
		 * response regardless of whether $username matched, so this
		 * endpoint can't be used to enumerate accounts.
		 * @Route("/forgot-password", methods={"POST"})
		 * @InterceptWith(Quellabs\Canvas\Validation\ValidateAspect::class, validator=App\Validation\ForgotPasswordFormValidator::class)
		 * @param Request $request
		 * @return Response
		 * @throws TemplateRenderException
		 */
		public function processForgotPassword(Request $request): Response {
			if (!$request->attributes->get('validation_passed', true)) {
				return $this->render('forgot_password.{{ template_ext }}', [
					'errors'    => $request->attributes->get('validation_errors', []),
					'submitted' => false,
				]);
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

				$this->em()->persist($token);
				$this->em()->flush();

				$this->passwordResetNotifier->send($user->getUsername(), $rawToken);
			}

			return $this->render('forgot_password.{{ template_ext }}', [
				'errors'    => [],
				'submitted' => true,
			]);
		}

		/**
		 * Display the reset-password form for the token in the query string.
		 * Token validity isn't checked here — processResetPassword() does
		 * that on submit, so an expired token still shows a clear error.
		 * @Route("/reset-password", methods={"GET"})
		 * @param Request $request
		 * @return Response
		 * @throws TemplateRenderException
		 */
		public function resetPassword(Request $request): Response {
			return $this->render('reset_password.{{ template_ext }}', [
				'token'  => (string)$request->query->get('token', ''),
				'errors' => [],
			]);
		}

		/**
		 * Consume the token and, if still valid, set the new password. On
		 * success, also marks every other outstanding token for the account
		 * as used, ending every reset link in flight, not just this one.
		 * @Route("/reset-password", methods={"POST"})
		 * @InterceptWith(Quellabs\Canvas\Validation\ValidateAspect::class, validator=App\Validation\ResetPasswordFormValidator::class)
		 * @param Request $request
		 * @return Response
		 * @throws TemplateRenderException
		 */
		public function processResetPassword(Request $request): Response {
			$rawToken = (string)$request->request->get('token', '');

			if (!$request->attributes->get('validation_passed', true)) {
				return $this->render('reset_password.{{ template_ext }}', [
					'token'  => $rawToken,
					'errors' => $request->attributes->get('validation_errors', []),
				]);
			}

			$password = $request->request->get('password');
			$confirmPassword = $request->request->get('confirm_password');

			if ($password !== $confirmPassword) {
				return $this->render('reset_password.{{ template_ext }}', [
					'token'  => $rawToken,
					'errors' => ['general' => ['Passwords do not match.']],
				]);
			}

			$resetToken = $this->findValidResetToken($rawToken);

			if ($resetToken === null) {
				return $this->render('reset_password.{{ template_ext }}', [
					'token'  => $rawToken,
					'errors' => ['general' => ['This password reset link is invalid or has expired.']],
				]);
			}

			try {
				$user = $this->em()->find(UserEntity::class, $resetToken->getUserId());
			} catch (OrmException $e) {
				$user = null;
			}

			if ($user === null) {
				return $this->render('reset_password.{{ template_ext }}', [
					'token'  => $rawToken,
					'errors' => ['general' => ['This password reset link is invalid or has expired.']],
				]);
			}

			$user->setPassword(password_hash($password, PASSWORD_DEFAULT));

			$now = new \DateTime();
			$resetToken->setUsedAt($now);

			try {
				$otherTokens = $this->em()->findBy(PasswordResetTokenEntity::class, ['userId' => $user->getId()]);
			} catch (QuelException $e) {
				$otherTokens = [];
			}

			foreach ($otherTokens as $otherToken) {
				if ($otherToken->getUsedAt() === null) {
					$otherToken->setUsedAt($now);
				}
			}

			$this->em()->flush();

			return new RedirectResponse('/login');
		}

		/**
		 * Looks up a password reset token by its raw value and returns it
		 * only when still usable: known, unused, and unexpired.
		 * @param string $rawToken As handed to PasswordResetNotifierInterface::send().
		 * @return PasswordResetTokenEntity|null
		 */
		private function findValidResetToken(string $rawToken): ?PasswordResetTokenEntity {
			try {
				$tokens = $this->em()->findBy(PasswordResetTokenEntity::class, ['tokenHash' => $this->hashResetToken($rawToken)]);
			} catch (QuelException $e) {
				return null;
			}

			$token = $tokens[0] ?? null;

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
			try {
				$users = $this->em()->findBy(UserEntity::class, ['username' => $username]);
				return empty($users) ? null : $users[0];
			} catch (QuelException $e) {
				return null;
			}
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
				
				$this->em()->persist($user);
				$this->em()->flush();
				
				return $user;
			} catch (OrmException $e) {
				error_log("User creation failed: " . $e->getMessage());
				throw new UserCreationException("Failed to create user account", 0, $e);
			}
		}
	}