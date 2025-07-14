<?php
	
	namespace Quellabs\Canvas\Discover;
	
	use Quellabs\Contracts\Context\MethodContext;
	use Quellabs\DependencyInjection\Provider\ServiceProvider;
	use Symfony\Component\HttpFoundation\Request;
	
	/**
	 * Service provider for Symfony HTTP Foundation Request objects.
	 */
	class RequestProvider extends ServiceProvider {
		
		/**
		 * The HTTP request instance to be provided by this service provider.
		 * @var Request The Symfony HTTP Foundation Request object
		 */
		private Request $request;
		
		/**
		 * Constructor - initializes the provider with a specific Request instance.
		 * @param Request $request The HTTP request instance to provide
		 */
		public function __construct(Request $request) {
			// Store the request instance for later provision
			$this->request = $request;
		}
		
		/**
		 * Determines if this provider can handle the requested class.
		 * @param string $className The fully qualified class name being requested
		 * @param array $metadata Additional metadata (unused in this implementation)
		 * @return bool True if this provider supports the Request class, false otherwise
		 */
		public function supports(string $className, array $metadata = []): bool {
			return $className === Request::class;
		}
		
		/**
		 * Creates and returns the Request instance.
		 * @param string $className The class name being requested (should be Request::class)
		 * @param array $dependencies Dependencies for the class (unused since we return existing instance)
		 * @param array $metadata Metadata as passed by Discover
		 * @param MethodContext|null $methodContext
		 * @return Request The HTTP request instance (return type should be Request, not Kernel)
		 */
		public function createInstance(string $className, array $dependencies, array $metadata, ?MethodContext $methodContext=null): object {
			// Return the pre-configured request instance
			return $this->request;
		}
	}