<?php

	namespace Quellabs\Canvas\Security\Policies;

	/**
	 * The medium tier of Canvas's three built-in SecurityHeaderPolicyInterface
	 * implementations (BasicSecurityHeaderPolicy / medium / StrictSecurityHeaderPolicy).
	 * Tightens BasicSecurityHeaderPolicy's defaults - a same-origin Content-Security-Policy
	 * baseline, a 2-year HSTS, and a tighter Referrer-Policy - without denying framing
	 * or adding headers BasicSecurityHeaderPolicy doesn't already model.
	 */
	class MediumSecurityHeaderPolicy extends BasicSecurityHeaderPolicy {

		/**
		 * Constructor
		 * @param string $frameOptions X-Frame-Options value (DENY, SAMEORIGIN, or ALLOW-FROM)
		 * @param bool $noSniff Enable X-Content-Type-Options: nosniff
		 * @param bool $xssProtection Enable X-XSS-Protection: 1; mode=block
		 * @param int $hstsMaxAge HSTS max-age in seconds (0 disables HSTS)
		 * @param bool $hstsIncludeSubdomains Include subdomains in HSTS
		 * @param bool $hstsPreload Add the preload directive to HSTS
		 * @param string $referrerPolicy Referrer-Policy value
		 * @param string|null $csp Content-Security-Policy value (null disables CSP)
		 */
		public function __construct(
			string  $frameOptions = 'SAMEORIGIN',
			bool    $noSniff = true,
			bool    $xssProtection = true,
			int     $hstsMaxAge = 63072000, // 2 years
			bool    $hstsIncludeSubdomains = true,
			bool    $hstsPreload = false,
			string  $referrerPolicy = 'strict-origin',
			?string $csp = "default-src 'self'"
		) {
			parent::__construct(
				$frameOptions,
				$noSniff,
				$xssProtection,
				$hstsMaxAge,
				$hstsIncludeSubdomains,
				$hstsPreload,
				$referrerPolicy,
				$csp
			);
		}
	}
