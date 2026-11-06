<?php

	namespace App\Controllers;

	use Quellabs\Canvas\Annotations\InterceptWith;
	use Quellabs\Canvas\Controllers\BaseController;

	/**
	 * Base controller for pages that require a logged-in, non-banned user.
	 * Class-level InterceptWith applies to every method and subclass, so
	 * controllers extending this don't need to declare these aspects
	 * themselves. For stricter per-action checks (e.g. deleting the account),
	 * see Quellabs\Canvas\Security\StepUpAuthenticationAspect.
	 *
	 * AccountEligibilityAspect is App\Aspects\AccountEligibilityAspect, not the
	 * package's Quellabs\CanvasAuthorization\AccountEligibilityAspect — that
	 * one depends on ObjectQuel's EntityManager and can't resolve here. It was
	 * scaffolded into src/Aspects/AccountEligibilityAspect.php by install:auth;
	 * edit it directly, it is now your code.
	 *
	 * throwOnFailure=true on both: AuthErrorHandler (scaffolded by
	 * install:auth, src/Errors/AuthErrorHandler.php) turns the thrown
	 * failure into a redirect to the login page.
	 *
	 * @InterceptWith(Quellabs\CanvasAuthorization\SessionAuthenticationAspect::class, throwOnFailure=true)
	 * @InterceptWith(App\Aspects\AccountEligibilityAspect::class, throwOnFailure=true)
	 */
	abstract class AuthenticatedController extends BaseController {
	}
