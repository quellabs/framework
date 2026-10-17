<?php

	// Application-wide security headers, applied by Quellabs\Canvas\Error\DefaultErrorHandler
	// to every error response (404, 405, 500, a thrown RateLimitException, ...) - cases where
	// no route ever matched, so a per-route @InterceptWith(SecurityHeadersAspect::class) never
	// ran. A header already set by that aspect, or by the error handler itself, always takes
	// precedence over this.
	//
	// Disabled by default so adding this file has no effect until explicitly turned on.

	return [
		// Master switch for the application-wide policy
		'enabled'                  => false,

		// X-Frame-Options value (DENY, SAMEORIGIN, or ALLOW-FROM)
		'frame_options'            => 'SAMEORIGIN',

		// Enable X-Content-Type-Options: nosniff
		'no_sniff'                 => true,

		// Enable X-XSS-Protection: 1; mode=block
		'xss_protection'           => true,

		// HSTS max-age in seconds (0 disables HSTS); only ever sent on HTTPS requests
		'hsts_max_age'             => 31536000, // 1 year

		// Include subdomains in the HSTS header
		'hsts_include_subdomains'  => true,

		// Referrer-Policy value
		'referrer_policy'          => 'strict-origin-when-cross-origin',

		// Content-Security-Policy value (null disables CSP)
		'csp'                      => null,
	];
