<?php
	
	namespace Quellabs\AnnotationReader\Reader;
	
	use Quellabs\AnnotationReader\AnnotationInterface;
	use Quellabs\AnnotationReader\Collection\AnnotationCollection;
	use Quellabs\AnnotationReader\Exception\AnnotationReaderException;
	
	/**
	 * Reads native attributes from classes and their visible members.
	 * @phpstan-import-type AnnotationSet from CachedAnnotationReader
	 */
	final class NativeAttributeAnnotationReader extends CachedAnnotationReader {
		
		/**
		 * Reads attributes from a class and its reflected members.
		 * @param \ReflectionClass<object> $reflection
		 * @return AnnotationSet
		 * @throws AnnotationReaderException
		 */
		protected function readAllObjectAnnotations(\ReflectionClass $reflection): array {
			$result = ['class' => $this->readAttributes($reflection), 'methods' => [], 'properties' => []];
			
			foreach ($reflection->getMethods() as $method) {
				$attributes = $this->readAttributes($method);
				
				if (!$attributes->isEmpty()) {
					$result['methods'][$method->getName()] = $attributes;
				}
			}
			
			foreach ($reflection->getProperties() as $property) {
				$attributes = $this->readAttributes($property);
				
				if (!$attributes->isEmpty()) {
					$result['properties'][$property->getName()] = $attributes;
				}
			}
			
			return $result;
		}
		
		/**
		 * Instantiates only attributes implementing the annotation contract.
		 * @param \ReflectionClass<object>|\ReflectionMethod|\ReflectionProperty $reflection
		 * @return AnnotationCollection
		 * @throws AnnotationReaderException
		 */
		private function readAttributes(\ReflectionClass|\ReflectionMethod|\ReflectionProperty $reflection): AnnotationCollection {
			$annotations = [];
			
			foreach ($reflection->getAttributes() as $attribute) {
				$class = $attribute->getName();
				
				if (!is_a($class, AnnotationInterface::class, true)) {
					continue;
				}
				
				try {
					$instance = $attribute->newInstance();
					
					if (!$instance instanceof AnnotationInterface) {
						continue;
					}
					
					$annotations[] = $instance;
				} catch (\Throwable $e) {
					if ($reflection instanceof \ReflectionClass) {
						$kind = 'class';
					} elseif ($reflection instanceof \ReflectionMethod) {
						$kind = ('method');
					} else {
						$kind = ('property');
					}
					
					if ($reflection instanceof \ReflectionClass) {
						$owner = $reflection->getName();
					} else {
						$owner = $reflection->getDeclaringClass()->getName() . '::' . $reflection->getName();
					}
					
					throw new AnnotationReaderException("Failed to instantiate attribute '{$class}' on {$kind} '{$owner}': {$e->getMessage()}", 0, $e);
				}
			}
			return new AnnotationCollection($annotations);
		}
		
		/**
		 * @param string $className
		 * @return string
		 */
		protected function generateCacheFilename(string $className): string {
			return str_replace('\\', '#', $className) . '.attributes.cache';
		}
		
		/**
		 * @return string
		 */
		protected function cacheFilePattern(): string {
			return '*.attributes.cache';
		}
		
		/**
		 * @param string $file
		 * @return bool
		 */
		protected function ownsCacheFile(string $file): bool {
			return str_ends_with($file, '.attributes.cache');
		}
	}
