<?php
	
	namespace Quellabs\AnnotationReader\Collection;
	
	/**
	 * Immutable collection class for managing annotations.
	 * @implements \ArrayAccess<int|string, object>
	 * @implements \Iterator<int, object>
	 */
	class AnnotationCollection implements \ArrayAccess, \Countable, \Iterator {
		
		/** @var array<int, object> Array to store annotation objects */
		private array $annotations = [];
		
		/** @var int Current position for iterator implementation */
		private int $position = 0;
		
		/**
		 * Constructor to initialize the collection with annotations.
		 * @param array<int, object> $annotations Array of annotation objects to store
		 */
		public function __construct(array $annotations = []) {
			$this->annotations = array_values($annotations); // Always flat numeric array
		}
		
		/**
		 * Check if an offset exists in the collection.
		 * @param mixed $offset The offset to check
		 * @return bool True if offset exists, false otherwise
		 */
		public function offsetExists(mixed $offset): bool {
			if (is_int($offset)) {
				return isset($this->annotations[$offset]);
			}
			
			if (is_string($offset) && ctype_digit($offset)) {
				return isset($this->annotations[(int)$offset]);
			}
			
			return is_string($offset) && $this->getFirst($offset) !== null;
		}
		
		/**
		 * Get the value at the specified offset.
		 * @param mixed $offset The annotation type (class name) or numeric index
		 * @return object|null The first annotation of that type or annotation at index
		 */
		public function offsetGet(mixed $offset): mixed {
			if (is_int($offset)) {
				return $this->annotations[$offset] ?? null;
			}
			
			if (is_string($offset) && ctype_digit($offset)) {
				return $this->annotations[(int)$offset] ?? null;
			}
			
			return is_string($offset) ? $this->getFirst($offset) : null;
		}
		
		/**
		 * Prevent setting values - collection is immutable.
		 * @param mixed $offset The offset to set
		 * @param mixed $value The value to set
		 * @throws \BadMethodCallException Always thrown as collection is immutable
		 */
		public function offsetSet(mixed $offset, mixed $value): void {
			throw new \BadMethodCallException('AnnotationCollection is immutable');
		}
		
		/**
		 * Prevent unsetting values - collection is immutable.
		 * @param mixed $offset The offset to unset
		 * @throws \BadMethodCallException Always thrown as collection is immutable
		 */
		public function offsetUnset(mixed $offset): void {
			throw new \BadMethodCallException('AnnotationCollection is immutable');
		}
		
		/**
		 * Get the number of annotations in the collection.
		 * @return int The count of annotations
		 */
		public function count(): int {
			return count($this->annotations);
		}
		
		/**
		 * Get the current annotation during iteration.
		 * @return object|null The current annotation or null if position is invalid
		 */
		public function current(): mixed {
			return $this->annotations[$this->position] ?? null;
		}
		
		/**
		 * Get the current key/position during iteration.
		 * @return int The current position
		 */
		public function key(): int {
			return $this->position;
		}
		
		/**
		 * Move to the next position during iteration.
		 * @return void
		 */
		public function next(): void {
			++$this->position;
		}
		
		/**
		 * Reset iterator position to the beginning.
		 * @return void
		 */
		public function rewind(): void {
			$this->position = 0;
		}
		
		/**
		 * Check if the current iterator position is valid.
		 * @return bool True if current position has a valid annotation
		 */
		public function valid(): bool {
			return isset($this->annotations[$this->position]);
		}
		
		/**
		 * Get the first annotation in the collection.
		 * @return object|null The first annotation or null if collection is empty
		 */
		public function first(): mixed {
			return $this->annotations[0] ?? null;
		}
		
		/**
		 * Get the last annotation in the collection.
		 * @return object|null The last annotation or null if collection is empty
		 */
		public function last(): mixed {
			return end($this->annotations) ?: null;
		}
		
		/**
		 * Check if the collection is empty.
		 * @return bool True if the collection contains no annotations
		 */
		public function isEmpty(): bool {
			return empty($this->annotations);
		}
		
		/**
		 * Convert the collection to a plain array with both numeric and class name keys.
		 * @return array<int|string, object> Array with both numeric indices and class names as keys
		 */
		public function toArray(): array {
			$result = [];
			$seenClasses = [];
			$numericIndex = 0;
			
			foreach ($this->annotations as $annotation) {
				// Get the fully qualified class name of the current annotation
				$className = get_class($annotation);
				
				// First occurrence of this class: use class name as key
				if (!isset($seenClasses[$className])) {
					$result[$className] = $annotation;
					$seenClasses[$className] = true;
					continue;
				}
				
				// Subsequent occurrences: use a numeric key
				$result[$numericIndex++] = $annotation;
			}
			
			return $result;
		}
		
		/**
		 * Convert the annotations to a linear array format.
		 * @return array<int, object> The annotations in linear array format
		 */
		public function toIndexedArray(): array {
			return $this->annotations;
		}
		
		/**
		 * @template T of object
		 * @param class-string<T> $className
		 * @return \Traversable<int, T>
		 */
		public function ofType(string $className): \Traversable {
			foreach ($this->annotations as $annotation) {
				if ($annotation instanceof $className) {
					yield $annotation;
				}
			}
		}
		
		/**
		 * Filter annotations based on a callback function.
		 * @param callable $callback Function to test each annotation
		 * @return self New filtered collection
		 */
		public function filter(callable $callback): self {
			$filtered = [];
			
			foreach ($this->annotations as $annotation) {
				if ($callback($annotation)) {
					$filtered[] = $annotation;
				}
			}
			
			return new self($filtered);
		}
		
		/**
		 * Get the first annotation of a specific type.
		 * @param string $className The annotation class name
		 * @return object|null The first annotation of that type or null if not found
		 */
		public function getFirst(string $className): mixed {
			foreach ($this->annotations as $annotation) {
				if ($annotation instanceof $className) {
					return $annotation;
				}
			}
			
			return null;
		}
		
		/**
		 * Get all annotations of a specific type.
		 * @param string $className The annotation class name
		 * @return self Collection of annotations of the specified type
		 */
		public function all(string $className): self {
			$result = [];
			
			foreach ($this->annotations as $annotation) {
				if ($annotation instanceof $className) {
					$result[] = $annotation;
				}
			}
			
			return new self($result);
		}
		
		/**
		 * Check if an annotation type has multiple instances.
		 * @param string $className The annotation class name
		 * @return bool True if there are multiple annotations of this type
		 */
		public function hasMultiple(string $className): bool {
			return $this->all($className)->count() > 1;
		}
		
		/**
		 * Merge this AnnotationCollection with another
		 * @param AnnotationCollection $other
		 * @return self
		 */
		public function merge(AnnotationCollection $other): self {
			return new self(array_merge($this->annotations, $other->toIndexedArray()));
		}
	}