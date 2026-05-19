<?php
	
	namespace Quellabs\DependencyInjection\Provider;
	
	use Quellabs\Contracts\Context\MethodContextInterface;
	use Quellabs\Contracts\DependencyInjection\ServiceProviderInterface;
	use Quellabs\Discover\Provider\AbstractProvider;
	
	/**
	 * Abstract base class for service providers with centralized autowiring
	 *
	 * This class serves as the foundation for all service providers in the application.
	 * It implements the ServiceProviderInterface and provides common functionality
	 * that all service providers will inherit.
	 */
	abstract class ServiceProvider extends AbstractProvider implements ServiceProviderInterface {

		/**
		 * Creates a new instance of the specified class with the provided dependencies
		 * @template T of object
		 * @param class-string<T> $className The fully qualified class name to instantiate
		 * @param array<int|string, mixed> $dependencies An array of resolved dependencies to pass to the constructor
		 * @param array<string, mixed> $metadata Metadata as passed by Discover
		 * @param MethodContextInterface|null $methodContext Optional method context
		 * @return T The newly created instance of the specified class
		 */
		public function createInstance(string $className, array $dependencies, array $metadata, ?MethodContextInterface $methodContext=null): object {
			// Use the splat operator (...) to unpack the dependency array
			// This allows passing each dependency as a separate argument to the constructor
			// instead of passing the entire array as a single argument
			return new $className(... $dependencies);
		}
		
		/**
		 * Returns true if the Dependency Injection provider supports the given class
		 * @param class-string $className
		 * @param array<string, mixed> $metadata
		 * @return bool
		 */
		abstract public function supports(string $className, array $metadata): bool;
		
		/**
		 * Normalizes a config value to bool, accepting bool or int (0/1) as valid input
		 * @param mixed $value The raw value from user configuration
		 * @param bool $default Fallback when the value is absent or an unrecognized type
		 * @return bool
		 */
		protected function normalizeBool(mixed $value, bool $default): bool {
			if (is_bool($value)) {
				return $value;
			} elseif (is_int($value)) {
				return (bool)$value;
			} else {
				return $default;
			}
		}
		
		/**
		 * Normalizes a config value to int, accepting int or bool as valid input
		 * @param mixed $value The raw value from user configuration
		 * @param int $default Fallback when the value is absent or an unrecognized type
		 * @return int
		 */
		protected function normalizeInt(mixed $value, int $default): int {
			if (is_int($value)) {
				return $value;
			} elseif (is_bool($value)) {
				return (int)$value;
			} else {
				return $default;
			}
		}
		
		/**
		 * Normalizes a config value to string
		 * @param mixed $value The raw value from user configuration
		 * @param string $default Fallback when the value is absent or an unrecognized type
		 * @return string
		 */
		protected function normalizeString(mixed $value, string $default): string {
			if (is_string($value)) {
				return $value;
			} else {
				return $default;
			}
		}
	}