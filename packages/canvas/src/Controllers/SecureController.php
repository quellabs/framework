<?php
	namespace Quellabs\Canvas\Controllers;

	use Quellabs\Canvas\Annotations\InterceptWith;
	use Quellabs\Canvas\Security\Policies\BasicSecurityHeaderPolicy;
	use Quellabs\Canvas\Security\SecurityHeadersAspect;

	/**
	 * Base controller that pins the basic security-header tier on all methods,
	 * regardless of whatever config/app.php's security_header_policy is set to.
	 * Extend this controller when you want this guaranteed, specific baseline -
	 * not "whatever the app-wide default happens to be".
	 *
	 * All methods in controllers extending SecureController get:
	 * - X-Frame-Options: SAMEORIGIN
	 * - X-Content-Type-Options: nosniff
	 * - X-XSS-Protection: 1; mode=block
	 * - Strict-Transport-Security (HTTPS only)
	 * - Referrer-Policy: strict-origin-when-cross-origin
	 *
	 * @InterceptWith(SecurityHeadersAspect::class, policyClass=BasicSecurityHeaderPolicy::class)
	 */
	abstract class SecureController extends BaseController {
	}