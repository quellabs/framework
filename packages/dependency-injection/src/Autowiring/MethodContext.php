<?php
	
	namespace Quellabs\DependencyInjection\Autowiring;
	
	use Quellabs\Contracts\Context\MethodContextInterface;
	
	/**
	 * This class implements the MethodContext contract and holds information about
	 * a specific method within a class instance, typically used during autowiring
	 * processes to provide context about which method is being processed.
	 */
	class MethodContext implements MethodContextInterface {
		
		/** @var string The class instance that contains the method */
		private string $class;
		
		/** @var string The name of the method being processed */
		private string $methodName;
		
		/** @var array<string, mixed> */
		private array $arguments;
		
		/** @var string|null Name of the parameter currently being autowirted */
		private ?string $currentParameterName = null;
		
		/**
		 * MethodContext constructor
		 * @param string $class The class instance that contains the method
		 * @param string $methodName The name of the method being processed
		 * @param array<string, mixed> $arguments Array of arguments passed to the method
		 */
		public function __construct(
			string $class,
			string $methodName,
			array $arguments = []
		) {
			$this->class = $class;
			$this->methodName = $methodName;
			$this->arguments = $arguments;
		}
		
		/**
		 * Get the class instance
		 * @return string The class name
		 */
		public function getClassName(): string {
			return $this->class;
		}
		
		/**
		 * Get the method name
		 * @return string The method name
		 */
		public function getMethodName(): string {
			return $this->methodName;
		}
		
		/**
		 * Get the arguments passed on the Route annotation
		 * @return array<string, mixed>
		 */
		public function getArguments(): array {
			return $this->arguments;
		}
		
		/**
		 * Sets the parameter that is currently being autowired
		 * @param string|null $name
		 * @return void
		 */
		public function setCurrentParameterName(?string $name): void {
			$this->currentParameterName = $name;
		}
		
		/**
		 * Gets the parameter that is currently being autowired
		 * @return string|null
		 */
		public function getCurrentParameterName(): ?string {
			return $this->currentParameterName;
		}
	}