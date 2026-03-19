<?php
	
	namespace Quellabs\Contracts\DependencyInjection;
	
	use Quellabs\Contracts\Context\MethodContextInterface;
	use Quellabs\Contracts\Discovery\ProviderInterface;
	
	/**
	 * Interface for service providers that support centralized autowiring
	 */
	interface ServiceProviderInterface extends ProviderInterface {
		
		/**
		 * Determine if this provider supports creating the given class
		 * @param string $className
		 * @param array $metadata
		 * @return bool
		 */
		public function supports(string $className, array $metadata): bool;
		
		/**
		 * Create an instance of the class with pre-resolved dependencies
		 * @param string $className The class to instantiate
		 * @param array $dependencies Pre-resolved constructor dependencies
		 * @param array $metadata Metadata as received by supports()
		 * @param MethodContextInterface|null $methodContext Method context of the caller
		 * @return object
		 */
		public function createInstance(string $className, array $dependencies, array $metadata, ?MethodContextInterface $methodContext=null): object;
	}