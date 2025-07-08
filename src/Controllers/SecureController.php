<?php
	
	namespace Quellabs\Canvas\Controllers;
	
	use Quellabs\Canvas\Annotations\InterceptWith;
	use Quellabs\Canvas\Security\SecurityHeadersAspect;
	
	/**
	 * Abstract base controller that automatically applies security headers to all HTTP responses.
	 *
	 * This controller uses aspect-oriented programming (AOP) to intercept all method calls
	 * and automatically apply security headers through the SecurityHeadersAspect.
	 *
	 * @InterceptWith(SecurityHeadersAspect::class) - Annotation that tells the framework
	 *                                                to intercept all method calls on this
	 *                                                class and its subclasses with the
	 *                                                SecurityHeadersAspect
	 */
	abstract class SecureController extends BaseController {
		
		/**
		 * This class serves as a base for controllers that need automatic security headers.
		 *
		 * Key features:
		 * - Abstract class - cannot be instantiated directly, must be extended
		 * - Extends BaseController - inherits all base controller functionality
		 * - Uses @InterceptWith annotation - applies SecurityHeadersAspect to all methods
		 * - All subclasses automatically get security headers on every response
		 *
		 * The SecurityHeadersAspect automatically adds OWASP-recommended headers:
		 * - X-Frame-Options: SAMEORIGIN (prevents clickjacking attacks)
		 * - X-Content-Type-Options: nosniff (prevents MIME type sniffing)
		 * - X-XSS-Protection: 1; mode=block (enables browser XSS filtering)
		 * - Strict-Transport-Security: max-age=31536000; includeSubDomains (HTTPS only)
		 * - Referrer-Policy: strict-origin-when-cross-origin (controls referrer info)
		 * - Content-Security-Policy: (optional, if configured in aspect constructor)
		 *
		 * Security benefits:
		 * - Prevents clickjacking via iframe embedding
		 * - Blocks MIME type confusion attacks
		 * - Enables browser-level XSS protection
		 * - Enforces HTTPS connections for 1 year
		 * - Controls referrer information leakage
		 * - Can prevent XSS/injection via CSP (if configured)
		 *
		 * Usage:
		 * Simply extend this class instead of BaseController to get automatic
		 * security headers on all controller actions without any additional code.
		 */
		
		// All methods in this class and its subclasses will automatically
		// have security headers applied through the SecurityHeadersAspect
		// No additional code needed - the aspect handles everything
	}