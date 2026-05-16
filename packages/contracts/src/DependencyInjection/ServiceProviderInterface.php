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
		 * @param class-string $className
		 * @param array<string, mixed> $metadata
		 * @return bool
		 */
		public function supports(string $className, array $metadata): bool;
		
		/**
		 * Create an instance of the class with pre-resolved dependencies
		 * @param class-string $className The class to instantiate
		 * @param array<int|string, mixed> $dependencies Pre-resolved constructor dependencies
		 * @param array<string, mixed> $metadata Metadata as received by supports()
		 * @param MethodContextInterface|null $methodContext Method context of the caller
		 * @return object
		 */
		public function createInstance(string $className, array $dependencies, array $metadata, ?MethodContextInterface $methodContext=null): object;
	}