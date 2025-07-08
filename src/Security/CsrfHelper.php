<?php
	
	namespace Quellabs\Canvas\Security;
	
	/**
	 * CSRF Helper class for generating CSRF tokens and form fields
	 *
	 * This class provides convenient methods for integrating CSRF protection
	 * into web forms and AJAX requests by generating hidden form fields,
	 * meta tags, and token values.
	 */
	class CsrfHelper {
		
		/**
		 * CSRF token manager instance
		 * @var CsrfTokenManager
		 */
		private CsrfTokenManager $csrfManager;
		
		/**
		 * Constructor - injects CSRF token manager dependency
		 * @param CsrfTokenManager $csrfManager The CSRF token manager instance
		 */
		public function __construct(CsrfTokenManager $csrfManager) {
			$this->csrfManager = $csrfManager;
		}
		
		/**
		 * Creates a hidden input field containing the CSRF token that can be
		 * embedded directly into HTML forms for protection against CSRF attacks.
		 * The token value is properly escaped to prevent XSS vulnerabilities.
		 * @param string $intention Optional token intention/scope for different form types
		 * @return string HTML hidden input field with CSRF token
		 */
		public function tokenField(string $intention = 'default'): string {
			// Get the CSRF token for the specified intention
			$token = $this->csrfManager->getToken($intention);
			
			// Return properly escaped hidden input field
			return sprintf(
				'<input type="hidden" name="_csrf_token" value="%s">',
				htmlspecialchars($token, ENT_QUOTES, 'UTF-8')
			);
		}
		
		/**
		 * Creates a meta tag containing the CSRF token that can be placed in the
		 * HTML head section. This allows JavaScript/AJAX requests to access the
		 * token value for inclusion in request headers or data.
		 * @param string $intention Optional token intention/scope for different request types
		 * @return string HTML meta tag with CSRF token
		 */
		public function metaTag(string $intention = 'default'): string {
			// Get the CSRF token for the specified intention
			$token = $this->csrfManager->getToken($intention);
			
			// Return properly escaped meta tag
			return sprintf(
				'<meta name="csrf-token" content="%s">',
				htmlspecialchars($token, ENT_QUOTES, 'UTF-8')
			);
		}
		
		/**
		 * Returns the raw CSRF token value for cases where you need to handle
		 * the token manually (e.g., custom form generation, API responses, etc.).
		 * @param string $intention Optional token intention/scope for different contexts
		 * @return string The CSRF token value
		 */
		public function getToken(string $intention = 'default'): string {
			return $this->csrfManager->getToken($intention);
		}
	}