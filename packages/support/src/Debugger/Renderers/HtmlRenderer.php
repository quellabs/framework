<?php
	
	namespace Quellabs\Support\Debugger\Renderers;
	
	use Quellabs\Support\Debugger\Assets\StyleSheet;
	use Quellabs\Support\Debugger\Assets\JavaScript;
	use Quellabs\Support\Debugger\Colors;
	
	/**
	 * HTML renderer for web output - Now properly utilizing BaseRenderer
	 */
	class HtmlRenderer extends BaseRenderer {
		
		/**
		 * Flag to ensure CSS/JS styles are only output once per request
		 * @var bool
		 */
		private bool $stylesOutputted = false;
		
		/**
		 * Render multiple variables for HTML output
		 * @param array $vars Variables to render
		 */
		public function render(array $vars): void {
			// Initialize type renderers
			$this->typeRenderers = [
				'string'   => [$this, 'renderString'],
				'integer'  => [$this, 'renderInteger'],
				'double'   => [$this, 'renderFloat'],
				'boolean'  => [$this, 'renderBoolean'],
				'NULL'     => [$this, 'renderNull'],
				'array'    => [$this, 'renderArray'],
				'object'   => [$this, 'renderObject'],
				'resource' => [$this, 'renderResource'],
			];
			
			// Only output styles once per request
			if (!$this->stylesOutputted) {
				echo StyleSheet::get();
				echo JavaScript::get();
				$this->stylesOutputted = true;
			}
			
			// Render each variable in its own container
			foreach ($vars as $var) {
				echo '<div class="canvas-dump">';
				$this->renderValue($var);
				echo '</div>';
				
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
		protected function renderString(string $value, $key = null, array $context = []): void {
			$this->renderKeyIfPresent($key);
			
			$truncated = $this->truncateString($value);
			$wasTruncated = $truncated !== $value;
			
			echo '<span style="color: ' . Colors::getHtml('string') . '">"' . $this->escapeHtml($truncated) . '"</span>';
			echo ' <span class="canvas-dump-type canvas-dump-length">(' . strlen($value) . ')';
			
			if ($wasTruncated) {
				echo ' truncated';
			}
			
			echo '</span>';
			$this->closeLineIfNotInline($context);
		}
		
		/**
		 * Render integer values
		 * @param int $value
		 * @param string|null $key
		 * @param array $context
		 */
		protected function renderInteger(int $value, $key = null, array $context = []): void {
			$this->renderKeyIfPresent($key);
			echo '<span style="color: ' . Colors::getHtml('integer') . '">' . $value . '</span>';
			$this->closeLineIfNotInline($context);
		}
		
		/**
		 * Render float values
		 * @param float $value
		 * @param string|null $key
		 * @param array $context
		 */
		protected function renderFloat(float $value, $key = null, array $context = []): void {
			$this->renderKeyIfPresent($key);
			echo '<span style="color: ' . Colors::getHtml('float') . '">' . $value . '</span>';
			$this->closeLineIfNotInline($context);
		}
		
		/**
		 * Render boolean values
		 * @param bool $value
		 * @param string|null $key
		 * @param array $context
		 */
		protected function renderBoolean(bool $value, $key = null, array $context = []): void {
			$this->renderKeyIfPresent($key);
			echo '<span style="color: ' . Colors::getHtml('boolean') . '">' . ($value ? 'true' : 'false') . '</span>';
			$this->closeLineIfNotInline($context);
		}
		
		/**
		 * Render null values
		 * @param null $value
		 * @param string|null $key
		 * @param array $context
		 */
		protected function renderNull($value, ?string $key = null, array $context = []): void {
			$this->renderKeyIfPresent($key);
			echo '<span style="color: ' . Colors::getHtml('null') . '">null</span>';
			$this->closeLineIfNotInline($context);
		}
		
		/**
		 * Render resource values
		 * @param resource $value
		 * @param string|null $key
		 * @param array $context
		 */
		protected function renderResource($value, ?string $key = null, array $context = []): void {
			$this->renderKeyIfPresent($key);
			
			echo '<span style="color: ' . Colors::getHtml('resource') . '">resource(' . $this->escapeHtml(get_resource_type($value)) . ')</span>';
			
			$this->closeLineIfNotInline($context);
		}
		
		/**
		 * Render arrays with collapsible HTML interface
		 * @param array $value
		 * @param string|null $key
		 * @param array $context
		 */
		protected function renderArray(array $value, ?string $key = null, array $context = []): void {
			$count = count($value);
			$id = $this->getNextId();
			
			$this->renderKeyIfPresent($key);
			
			if ($count === 0) {
				echo '<span style="color: ' . Colors::getHtml('array') . '">array:0</span> []';
				$this->closeLineIfNotInline($context);
				return;
			}
			
			// Check if array should be truncated
			$shouldTruncate = $this->shouldTruncateArray($value);
			$displayArray = $shouldTruncate ? $this->getTruncatedArray($value) : $value;
			$displayCount = count($displayArray);
			
			echo '<div id="canvas-dump-' . $id . '" class="canvas-dump-item">';
			echo '<span class="canvas-dump-expandable" onclick="toggleCanvasDump(' . $id . ')">';
			echo '<span class="canvas-dump-toggle">−</span>';
			echo '<span style="color: ' . Colors::getHtml('array') . '">array:' . $count . '</span>';
			
			if ($shouldTruncate) {
				echo ' <span class="canvas-dump-type">(showing ' . $displayCount . ')</span>';
			}
			
			echo ' [</span>';
			echo '<div class="canvas-dump-content">';
			
			$this->increaseDepth();
			
			foreach ($displayArray as $arrayKey => $arrayValue) {
				echo '<div class="canvas-dump-line">' . $this->getIndent();
				$this->renderValue($arrayValue, $arrayKey, ['inline' => false]);
				echo '</div>';
			}
			
			if ($shouldTruncate) {
				echo '<div class="canvas-dump-line">' . $this->getIndent();
				echo '<span style="color: ' . Colors::getHtml('null') . '">... and ' . ($count - $displayCount) . ' more elements</span>';
				echo '</div>';
			}
			
			$this->decreaseDepth();
			
			echo '<div class="canvas-dump-line">' . $this->getIndent() . ']</div>';
			echo '</div></div>';
		}
		
		/**
		 * Render objects with HTML formatting
		 * @param object $value
		 * @param string|null $key
		 * @param array $context
		 */
		protected function renderObject(object $value, ?string $key = null, array $context = []): void {
			$className = get_class($value);
			$objectId = spl_object_id($value);
			$id = $this->getNextId();
			
			$this->renderKeyIfPresent($key);
			
			echo '<div id="canvas-dump-' . $id . '" class="canvas-dump-item">';
			echo '<span class="canvas-dump-expandable" onclick="toggleCanvasDump(' . $id . ')">';
			echo '<span class="canvas-dump-toggle">−</span>';
			echo '<span style="color: ' . Colors::getHtml('object') . '">' . $this->escapeHtml($className) . '</span> {#' . $objectId;
			
			// Add string representation if available
			$stringRepresentation = $this->getObjectStringRepresentation($value);
			
			if ($stringRepresentation) {
				echo '<span style="color: ' . Colors::getHtml('string') . '">' . $stringRepresentation . '</span>';
			}
			
			echo '</span>';
			echo '<div class="canvas-dump-content">';
			
			$this->increaseDepth();
			$properties = $this->getObjectProperties($value);
			
			foreach ($properties as $property) {
				$propertyValue = $this->getPropertyValue($property, $value);
				$visibility = $this->getVisibilitySymbol($property);
				
				if ($property->isPublic()) {
					$visibilityClass = '';
				} elseif ($property->isProtected()) {
					$visibilityClass = 'canvas-dump-protected';
				} else {
					$visibilityClass = 'canvas-dump-private';
				}
				
				echo '<div class="canvas-dump-line">' . $this->getIndent();
				echo '<span class="' . $visibilityClass . '" style="color: ' . Colors::getHtml('property') . '">';
				echo $visibility . $this->escapeHtml($property->getName()) . '</span>: ';
				
				if (is_string($propertyValue) && str_starts_with($propertyValue, '***')) {
					echo '<span style="color: ' . Colors::getHtml('null') . '">' . $this->escapeHtml($propertyValue) . '</span>';
				} else {
					$this->renderValue($propertyValue, null, ['inline' => true]);
				}
				
				echo '</div>';
			}
			
			$this->decreaseDepth();
			echo '<div class="canvas-dump-line">' . $this->getIndent() . '}</div>';
			echo '</div></div>';
			
			// Remove from circular reference tracking
			$this->removeFromCircularTracking($value);
		}
		
		/**
		 * Render circular reference indicator
		 * @param object $object
		 * @param string|null $key
		 */
		protected function renderCircularReference(object $object, string $key = null): void {
			$className = get_class($object);
			$objectId = spl_object_id($object);
			
			$this->renderKeyIfPresent($key);
			
			echo '<span style="color: ' . Colors::getHtml('null') . '">*CIRCULAR REFERENCE* ';
			echo $this->escapeHtml($className) . ' {#' . $objectId . '}</span>';
			echo '</div>';
		}
		
		/**
		 * Render max depth indicator
		 * @param string|null $key
		 */
		protected function renderMaxDepthIndicator(string $key = null): void {
			$this->renderKeyIfPresent($key);
			echo '<span style="color: ' . Colors::getHtml('null') . '">*MAX DEPTH REACHED*</span>';
			echo '</div>';
		}
		
		/**
		 * Render unknown type
		 * @param mixed $value
		 * @param string|null $key
		 * @param array $context
		 */
		protected function renderUnknownType(mixed $value, string $key = null, array $context = []): void {
			$this->renderKeyIfPresent($key);
			$type = gettype($value);
			echo '<span style="color: ' . Colors::getHtml('null') . '">unknown(' . $this->escapeHtml($type) . ')</span>';
			$this->closeLineIfNotInline($context);
		}
		
		/**
		 * Helper: Render key if present
		 * @param string|null $key
		 */
		private function renderKeyIfPresent(?string $key): void {
			if ($key !== null) {
				echo '<span class="canvas-dump-key" style="color: ' . Colors::getHtml('key') . '">';
				echo '"' . $this->escapeHtml($key) . '"</span> => ';
			}
		}
		
		/**
		 * Helper: Close line div if not inline
		 * @param array $context
		 */
		private function closeLineIfNotInline(array $context): void {
			if (!($context['inline'] ?? false)) {
				echo '</div>';
			}
		}
		
		/**
		 * Get string representation of common objects
		 * @param object $object
		 * @return string
		 */
		private function getObjectStringRepresentation(object $object): string {
			if ($object instanceof \DateTime || $object instanceof \DateTimeImmutable) {
				return ' "' . $this->escapeHtml($object->format('Y-m-d H:i:s T')) . '"';
			}
			
			if (!method_exists($object, '__toString')) {
				return '';
			}
			
			try {
				$stringValue = (string)$object;
				$truncated = $this->truncateString($stringValue);
				
				if ($truncated) {
					return ' "' . $this->escapeHtml($truncated) . '"';
				}
			} catch (\Exception $e) {
				return ' "*toString() error: ' . $this->escapeHtml($e->getMessage()) . '*"';
			}
		}
		
		/**
		 * Override beforeRenderValue to handle HTML-specific setup
		 * @param mixed $value
		 * @param string|null $key
		 * @param array $context
		 */
		protected function beforeRenderValue(mixed $value, ?string $key = null, array $context = []): void {
			// Only start a new line div if we're not inline and not handling complex types
			if (!($context['inline'] ?? false) && !in_array(gettype($value), ['array', 'object'])) {
				echo '<div class="canvas-dump-line">' . $this->getIndent();
			}
		}
		
		/**
		 * Safely escape any string for HTML output
		 * Centralized escaping method for consistency
		 */
		private function escapeHtml(string $value): string {
			return htmlspecialchars($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
		}
	}