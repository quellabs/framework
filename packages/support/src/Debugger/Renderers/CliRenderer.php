<?php
	
	namespace Quellabs\Support\Debugger\Renderers;
	
	use Quellabs\Support\Debugger\Colors;
	
	/**
	 * CLI renderer for terminal output with ANSI colors - Now properly utilizing BaseRenderer
	 */
	class CliRenderer extends BaseRenderer {
		
		/**
		 * Render multiple variables for CLI output
		 * @param array $vars Variables to render
		 */
		public function render(array $vars): void {
			// Initialize type renderers
			$this->typeRenderers = [
				'string' => [$this, 'renderString'],
				'integer' => [$this, 'renderInteger'],
				'double' => [$this, 'renderFloat'],
				'boolean' => [$this, 'renderBoolean'],
				'NULL' => [$this, 'renderNull'],
				'array' => [$this, 'renderArray'],
				'object' => [$this, 'renderObject'],
				'resource' => [$this, 'renderResource'],
			];
			
			// Render each variable with separation
			foreach ($vars as $var) {
				$this->renderValue($var);
				echo "\n\n";
				
				// Reset for next variable
				$this->processedObjects = [];
			}
		}
		
		/**
		 * Render string values
		 * @param string $value
		 * @param string|null $key
		 * @param array $context
		 */
		protected function renderString(string $value, ?string $key = null, array $context = []): void {
			$this->renderKeyIfPresent($key);
			
			$truncated = $this->truncateString($value);
			$wasTruncated = $truncated !== $value;
			
			echo Colors::wrapAnsi('"' . $truncated . '"', 'string');
			echo ' (' . strlen($value) . ')';
			
			if ($wasTruncated) {
				echo ' ' . Colors::wrapAnsi('[truncated]', 'null');
			}
			
			$this->newLineIfNotInline($context);
		}
		
		/**
		 * Render integer values
		 * @param int $value
		 * @param string|null $key
		 * @param array $context
		 */
		protected function renderInteger(int $value, ?string $key = null, array $context = []): void {
			$this->renderKeyIfPresent($key);
			echo Colors::wrapAnsi((string)$value, 'integer');
			$this->newLineIfNotInline($context);
		}
		
		/**
		 * Render float values
		 * @param float $value
		 * @param string|null $key
		 * @param array $context
		 */
		protected function renderFloat(float $value, ?string $key = null, array $context = []): void {
			$this->renderKeyIfPresent($key);
			echo Colors::wrapAnsi((string)$value, 'float');
			$this->newLineIfNotInline($context);
		}
		
		/**
		 * Render boolean values
		 * @param bool $value
		 * @param string|null $key
		 * @param array $context
		 */
		protected function renderBoolean(bool $value, ?string $key = null, array $context = []): void {
			$this->renderKeyIfPresent($key);
			echo Colors::wrapAnsi($value ? 'true' : 'false', 'boolean');
			$this->newLineIfNotInline($context);
		}
		
		/**
		 * Render null values
		 * @param null $value
		 * @param string|null $key
		 * @param array $context
		 */
		protected function renderNull(null $value, ?string $key = null, array $context = []): void {
			$this->renderKeyIfPresent($key);
			echo Colors::wrapAnsi('null', 'null');
			$this->newLineIfNotInline($context);
		}
		
		/**
		 * Render resource values
		 * @param resource $value
		 * @param string|null $key
		 * @param array $context
		 */
		protected function renderResource($value, ?string $key = null, array $context = []): void {
			$this->renderKeyIfPresent($key);
			echo Colors::wrapAnsi('resource(' . get_resource_type($value) . ')', 'resource');
			$this->newLineIfNotInline($context);
		}
		
		/**
		 * Render arrays for CLI output
		 * @param array $value
		 * @param string|null $key
		 * @param array $context
		 */
		protected function renderArray(array $value, ?string $key = null, array $context = []): void {
			$count = count($value);
			
			$this->renderKeyIfPresent($key);
			
			if ($count === 0) {
				echo Colors::wrapAnsi('array:0', 'array') . ' []';
				$this->newLineIfNotInline($context);
				return;
			}
			
			// Check if array should be truncated
			$shouldTruncate = $this->shouldTruncateArray($value);
			$displayArray = $shouldTruncate ? $this->getTruncatedArray($value) : $value;
			$displayCount = count($displayArray);
			
			echo Colors::wrapAnsi('array:' . $count, 'array');
			
			if ($shouldTruncate) {
				echo ' ' . Colors::wrapAnsi('(showing ' . $displayCount . ')', 'null');
			}
			
			echo " [\n";
			
			$this->increaseDepth();
			foreach ($displayArray as $arrayKey => $arrayValue) {
				echo $this->getIndent();
				$this->renderValue($arrayValue, $arrayKey, ['inline' => false]);
			}
			
			if ($shouldTruncate) {
				echo $this->getIndent();
				echo Colors::wrapAnsi('... and ' . ($count - $displayCount) . ' more elements', 'null');
				echo "\n";
			}
			
			$this->decreaseDepth();
			echo $this->getIndent() . ']';
			$this->newLineIfNotInline($context);
		}
		
		/**
		 * Render objects for CLI output
		 * @param object $value
		 * @param string|null $key
		 * @param array $context
		 */
		protected function renderObject(object $value, ?string $key = null, array $context = []): void {
			$className = get_class($value);
			$objectId = spl_object_id($value);
			
			$this->renderKeyIfPresent($key);
			
			echo Colors::wrapAnsi($className, 'object') . ' {#' . $objectId;
			
			// Add string representation if available
			$stringRepresentation = $this->getObjectStringRepresentation($value);
			
			if ($stringRepresentation) {
				echo ' ' . Colors::wrapAnsi($stringRepresentation, 'string');
			}
			
			echo "\n";
			
			$this->increaseDepth();
			$properties = $this->getObjectProperties($value);
			
			foreach ($properties as $property) {
				$propertyValue = $this->getPropertyValue($property, $value);
				$visibility = $this->getVisibilitySymbol($property);
				
				echo $this->getIndent();
				echo Colors::wrapAnsi($visibility . $property->getName(), 'property') . ': ';
				
				if (is_string($propertyValue) && str_starts_with($propertyValue, '***')) {
					echo Colors::wrapAnsi($propertyValue, 'null');
					echo "\n";
				} else {
					$this->renderValue($propertyValue, null, ['inline' => true]);
				}
			}
			
			$this->decreaseDepth();
			echo $this->getIndent() . '}';
			$this->newLineIfNotInline($context);
			
			// Remove from circular reference tracking
			$this->removeFromCircularTracking($value);
		}
		
		/**
		 * Override circular reference rendering for CLI
		 * @param object $object
		 * @param string|null $key
		 */
		protected function renderCircularReference(object $object, string $key = null): void {
			$this->renderKeyIfPresent($key);
			$className = get_class($object);
			$objectId = spl_object_id($object);
			
			echo Colors::wrapAnsi('*CIRCULAR REFERENCE* ', 'null');
			echo Colors::wrapAnsi($className, 'object') . ' {#' . $objectId . '}';
			echo "\n";
		}
		
		/**
		 * Override max depth rendering for CLI
		 * @param string|null $key
		 */
		protected function renderMaxDepthIndicator(string $key = null): void {
			$this->renderKeyIfPresent($key);
			echo Colors::wrapAnsi('*MAX DEPTH REACHED*', 'null');
			echo "\n";
		}
		
		/**
		 * Override unknown type rendering for CLI
		 * @param mixed $value
		 * @param string|null $key
		 * @param array $context
		 */
		protected function renderUnknownType(mixed $value, string $key = null, array $context = []): void {
			$this->renderKeyIfPresent($key);
			$type = gettype($value);
			echo Colors::wrapAnsi('unknown(' . $type . ')', 'null');
			$this->newLineIfNotInline($context);
		}
		
		/**
		 * Helper: Render key if present
		 * @param string|null $key
		 */
		private function renderKeyIfPresent(?string $key): void {
			if ($key !== null) {
				echo Colors::wrapAnsi('"' . $key . '"', 'key') . ' => ';
			}
		}
		
		/**
		 * Helper: Add newline if not inline
		 * @param array $context
		 */
		private function newLineIfNotInline(array $context): void {
			if (!($context['inline'] ?? false)) {
				echo "\n";
			}
		}
		
		/**
		 * Get string representation of common objects
		 * @param object $object
		 * @return string
		 */
		private function getObjectStringRepresentation(object $object): string {
			if ($object instanceof \DateTime || $object instanceof \DateTimeImmutable) {
				return '"' . $object->format('Y-m-d H:i:s T') . '"';
			}
			
			if (method_exists($object, '__toString')) {
				try {
					$stringValue = (string)$object;
					$truncated = $this->truncateString($stringValue);
					
					if ($truncated) {
						return '"' . $truncated . '"';
					}
				} catch (\Exception $e) {
					return '"*toString() error*"';
				}
			}
			
			return '';
		}
		
		/**
		 * Override beforeRenderValue to handle CLI-specific setup
		 * @param mixed $value
		 * @param string|null $key
		 * @param array $context
		 */
		protected function beforeRenderValue(mixed $value, ?string $key = null, array $context = []): void {
			// Add indentation for non-inline renders
			if (!($context['inline'] ?? false) && !in_array(gettype($value), ['array', 'object'])) {
				echo $this->getIndent();
			}
		}
	}