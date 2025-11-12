<?php
	
	namespace Quellabs\Discover\Exceptions;
	
	use Quellabs\Contracts\Discovery\ProviderDefinition;
	
	/**
	 * Exception thrown when a service provider cannot be instantiated.
	 */
	class ProviderInstantiationException extends \RuntimeException {

		/** Class does not exist or cannot be autoloaded */
		const int CLASS_NOT_FOUND = 1;
		
		/** Constructor signature doesn't match provided arguments */
		const int CONSTRUCTOR_ARGS_MISMATCH = 2;
		
		/** Instantiation failed (constructor threw exception, abstract class, etc.) */
		const int INSTANTIATION_FAILED = 3;
		
		/** Instantiated object doesn't implement required interface */
		const int INTERFACE_NOT_IMPLEMENTED = 4;
		
		/** Provider configuration/setup method failed after instantiation */
		const int CONFIGURATION_FAILED = 5;
		
		/** The provider definition that failed to instantiate */
		private ProviderDefinition $definition;
		
		/**
		 * @param string $message Human-readable error description
		 * @param int $code One of the class constants indicating failure type
		 * @param ProviderDefinition $definition The provider that failed
		 * @param \Throwable|null $previous The underlying cause (if any)
		 */
		public function __construct(
			string             $message,
			int                $code,
			ProviderDefinition $definition,
			?\Throwable        $previous = null
		) {
			parent::__construct($message, $code, $previous);
			$this->definition = $definition;
		}
		
		/**
		 * Returns the complete provider definition that failed.
		 * @return ProviderDefinition
		 */
		public function getDefinition(): ProviderDefinition {
			return $this->definition;
		}
		
		/**
		 * Extracts key diagnostic information from the provider definition.
		 * Useful for logging and debugging without exposing the full definition object.
		 * @return array{className: string, family: string, configFiles: array, metadata: array}
		 */
		public function getContextInfo(): array {
			return [
				'className'   => $this->definition->className,
				'family'      => $this->definition->family,
				'configFiles' => $this->definition->configFiles,
				'metadata'    => $this->definition->metadata,
			];
		}
	}