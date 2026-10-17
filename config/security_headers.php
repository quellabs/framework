<?php

	// Application-wide security headers, applied by Quellabs\Canvas\Security\ResponseFinalizer
	// to every response - including a route miss (404/405), a before-aspect short circuit, and
	// an exception response - which a per-route @InterceptWith(SecurityHeadersAspect::class) can't
	// reach. A header already set by a route-specific aspect always takes precedence over this.
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
