<?php
	
	declare(strict_types=1);
	
	namespace Quellabs\AnnotationReader\Annotation;
	
	use Quellabs\AnnotationReader\AnnotationInterface;
	
	/**
	 * Constructs annotations with their declared constructor convention.
	 */
	final class AnnotationFactory {
		
		/**
		 * Constructs a legacy array annotation or a natural-argument annotation.
		 * @param class-string<AnnotationInterface> $class
		 * @param array<array-key, mixed> $parameters
		 * @return AnnotationInterface
		 * @throws \ReflectionException
		 */
		public static function create(string $class, array $parameters): AnnotationInterface {
			$constructor = (new \ReflectionClass($class))->getConstructor();
			
			if ($constructor !== null && $constructor->getNumberOfParameters() === 1) {
				$parameter = $constructor->getParameters()[0];
				$type = $parameter->getType();
				
				if ($type instanceof \ReflectionNamedType && $type->getName() === 'array' && !$type->allowsNull()) {
					return new $class($parameters);
				}
			}
			
			return new $class(...$parameters);
		}
	}
