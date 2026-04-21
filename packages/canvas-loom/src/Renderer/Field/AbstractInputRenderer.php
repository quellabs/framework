<?php
	
	namespace Quellabs\Canvas\Loom\Renderer\Field;
	
	use Quellabs\Canvas\Loom\Loom;
	
	/**
	 * Base class for all field input renderers.
	 * Provides shared value resolution and validation attribute building
	 * used across all input types.
	 *
	 * Does not implement RendererInterface — input renderers are not node
	 * renderers and are never called directly by the Loom engine. They are
	 * instantiated and called by FieldRenderer only.
	 */
	abstract class AbstractInputRenderer {
		
		/**
		 * @param Loom $loom The active Loom engine instance
		 */
		public function __construct(protected readonly Loom $loom) {}
		
		/**
		 * Escape a value for safe HTML output.
		 * @param mixed $value
		 * @return string
		 */
		protected function e(mixed $value): string {
			return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
		}
		
		/**
		 * Render the input element.
		 * Receives pre-resolved values from FieldRenderer so input renderers
		 * do not need to repeat resolution logic.
		 * @param string $id          Element id attribute
		 * @param string $name        Field name
		 * @param string $value       Resolved field value
		 * @param array  $properties  Full node properties
		 * @param string $pacField    Rendered data-pac-field attribute string
		 * @param string $pacBind     Rendered data-pac-bind attribute string
		 * @return string
		 */
		abstract public function renderInput(
			string $id,
			string $name,
			string $value,
			array  $properties,
			string $pacField = '',
			string $pacBind = ''
		): string;
		
		/**
		 * Build a string of HTML validation attributes from node properties.
		 * Only attributes that are present in the properties array are rendered.
		 * @param array $properties Node properties
		 * @return string
		 */
		protected function buildValidationAttrs(array $properties): string {
			$attrs = '';
			
			// Boolean attributes — present means true
			if (!empty($properties['required'])) {
				$attrs .= ' required';
			}
			
			if (!empty($properties['disabled'])) {
				$attrs .= ' disabled';
			}
			
			if (!empty($properties['readonly'])) {
				$attrs .= ' readonly';
			}
			
			// Value attributes — only rendered when explicitly set
			if (isset($properties['maxlength'])) {
				$attrs .= ' maxlength="' . (int) $properties['maxlength'] . '"';
			}
			
			if (isset($properties['minlength'])) {
				$attrs .= ' minlength="' . (int) $properties['minlength'] . '"';
			}
			
			if (isset($properties['min'])) {
				$attrs .= ' min="' . (float) $properties['min'] . '"';
			}
			
			if (isset($properties['max'])) {
				$attrs .= ' max="' . (float) $properties['max'] . '"';
			}
			
			if (isset($properties['step'])) {
				// step can be "any" or a number
				$step   = $properties['step'];
				$attrs .= ' step="' . ($step === 'any' ? 'any' : (float) $step) . '"';
			}
			
			if (isset($properties['pattern'])) {
				$attrs .= ' pattern="' . $this->e($properties['pattern']) . '"';
			}
			
			if (isset($properties['autocomplete'])) {
				$attrs .= ' autocomplete="' . $this->e($properties['autocomplete']) . '"';
			}
			
			return $attrs;
		}
		
		/**
		 * Resolve the field value from the data array or fall back to the node definition.
		 * @param string $name       Field name, used as path into the data array
		 * @param array  $properties Node properties
		 * @return string
		 */
		protected function resolveValue(string $name, array $properties): string {
			$data = $this->loom->getData();
			
			if (!empty($data) && $name) {
				$value = $this->getNestedValue($data, $name);
				
				if ($value !== null) {
					return (string) $value;
				}
			}
			
			return (string) ($properties['value'] ?? '');
		}
		
		/**
		 * Get a nested value from an array using dot and bracket notation.
		 * @param array  $data
		 * @param string $path
		 * @return mixed
		 */
		protected function getNestedValue(array $data, string $path): mixed {
			$parts   = preg_split('/[.\[\]]+/', $path, -1, PREG_SPLIT_NO_EMPTY);
			$current = $data;
			
			foreach ($parts as $part) {
				if (!isset($current[$part])) {
					return null;
				}
				
				$current = $current[$part];
			}
			
			return $current;
		}
	}