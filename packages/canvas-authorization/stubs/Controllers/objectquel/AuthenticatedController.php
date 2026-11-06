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
	 * throwOnFailure=true on both: AuthErrorHandler (scaffolded by
	 * install:auth, src/Errors/AuthErrorHandler.php) turns the thrown
	 * failure into a redirect to the login page.
	 *
	 * @InterceptWith(Quellabs\CanvasAuthorization\SessionAuthenticationAspect::class, throwOnFailure=true)
	 * @InterceptWith(Quellabs\CanvasAuthorization\AccountEligibilityAspect::class, userEntityClass=App\Entities\UserEntity::class, throwOnFailure=true)
	 */
	abstract class AuthenticatedController extends BaseController {
	}
