<?php

	namespace Quellabs\Canvas\Security;

	use Symfony\Component\HttpFoundation\Request;
	use Symfony\Component\HttpFoundation\Response;

	/**
	 * Applies response-wide policies at the single point every response passes
	 * through (Kernel::handle()), regardless of which path produced it: a normal
	 * controller response, a before-aspect short circuit (AspectDispatcher skips
	 * AfterAspectInterface entirely in that case), an exception turned into a
	 * response, a route miss (404/405), or a legacy response.
	 *
	 * Per-route @InterceptWith aspects only run when a controller method is
	 * actually dispatched, so they cannot cover the other cases above - that
	 * gap is what this class exists to close, for the policies that are meant
	 * to apply everywhere rather than being genuinely route-specific.
	 */
	class ResponseFinalizer {

		/** @var SecurityHeaderPolicy|null Application-wide security header policy, null when not configured */
		private ?SecurityHeaderPolicy $securityHeaderPolicy;

		/**
		 * Constructor
		 * @param SecurityHeaderPolicy|null $securityHeaderPolicy Policy to apply application-wide, or null to skip
		 */
		public function __construct(?SecurityHeaderPolicy $securityHeaderPolicy) {
			$this->securityHeaderPolicy = $securityHeaderPolicy;
		}

		/**
		 * Apply every response-wide policy to the given response, in place
		 * @param Request $request The request that produced this response
		 * @param Response $response The response to finalize, modified in place
		 * @return void
		 */
		public function finalize(Request $request, Response $response): void {
			// Rate-limit headers are read from request attributes set by RateLimitAspect::before(),
			// which are present regardless of whether that aspect's own after() ever ran. Never
			// invents limit data: a request that never went through rate limiting has no attributes
			// set, so this is a no-op for it (e.g. a plain 404/405 route miss).
			RateLimitAspect::applyHeadersFromRequest($request, $response);

			// Global security headers only apply when explicitly configured (config/security_headers.php).
			// Route-specific headers set earlier by SecurityHeadersAspect::after() are left untouched,
			// since SecurityHeaderPolicy only ever sets a header that isn't already present.
			if ($this->securityHeaderPolicy !== null) {
				$this->securityHeaderPolicy->apply($request, $response);
			}
		}
	}
