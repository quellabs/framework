<?php

	namespace Quellabs\Canvas\Security;

	use Quellabs\Canvas\AOP\Contracts\AfterAspectInterface;
	use Quellabs\Canvas\Routing\Contracts\MethodContextInterface;
	use Symfony\Component\HttpFoundation\Response;

	/**
	 * Security Headers Aspect
	 *
	 * Automatically adds security-related HTTP headers to protect against common attacks.
	 * Implements OWASP security header recommendations for web applications.
	 *
	 * Headers added:
	 * - X-Content-Type-Options: Prevents MIME type sniffing
	 * - X-Frame-Options: Prevents clickjacking attacks
	 * - X-XSS-Protection: Enables browser XSS filtering
	 * - Strict-Transport-Security: Enforces HTTPS connections
	 * - Referrer-Policy: Controls referrer information
	 * - Content-Security-Policy: Prevents XSS and data injection
	 *
	 * As an AfterAspectInterface, this only runs on a matched route (including a
	 * before-aspect short circuit on that route, which AspectDispatcher still runs
	 * after aspects against). A route miss or an exception that never reaches a
	 * matched route's aspects is covered by DefaultErrorHandler instead, via
	 * config/security_headers.php, using the same SecurityHeaderPolicy.
	 */
	class SecurityHeadersAspect implements AfterAspectInterface {

		/** @var SecurityHeaderPolicy Shared header-writing logic */
		private SecurityHeaderPolicy $policy;

		/**
		 * Constructor
		 * @param string $frameOptions X-Frame-Options value (DENY, SAMEORIGIN, or ALLOW-FROM)
		 * @param bool $noSniff Enable X-Content-Type-Options: nosniff
		 * @param bool $xssProtection Enable X-XSS-Protection: 1; mode=block
		 * @param int $hstsMaxAge HSTS max-age in seconds (0 disables HSTS)
		 * @param bool $hstsIncludeSubdomains Include subdomains in HSTS
		 * @param string $referrerPolicy Referrer-Policy value
		 * @param string|null $csp Content-Security-Policy value (null disables CSP)
		 */
		public function __construct(
			string  $frameOptions = 'SAMEORIGIN',
			bool    $noSniff = true,
			bool    $xssProtection = true,
			int     $hstsMaxAge = 31536000, // 1 year
			bool    $hstsIncludeSubdomains = true,
			string  $referrerPolicy = 'strict-origin-when-cross-origin',
			?string $csp = null
		) {
			$this->policy = new SecurityHeaderPolicy(
				$frameOptions,
				$noSniff,
				$xssProtection,
				$hstsMaxAge,
				$hstsIncludeSubdomains,
				$referrerPolicy,
				$csp
			);
		}

		/**
		 * Adds security headers to the response after the controller method executes
		 * @param MethodContextInterface $context The method execution context
		 * @param Response $response The response to modify
		 */
		public function after(MethodContextInterface $context, Response $response): void {
			$this->policy->apply($context->getRequest(), $response);
		}
	}
