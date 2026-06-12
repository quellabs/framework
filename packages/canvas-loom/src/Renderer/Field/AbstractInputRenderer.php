<?php
	
	declare(strict_types=1);
	
	namespace Quellabs\Canvas\Loom\Renderer\Field;
	
	use Quellabs\Canvas\Loom\EscapesTrait;
	
	/**
	 * Base class for all field input renderers.
	 * Provides shared value resolution and validation attribute building
	 * used across all input types.
	 *
	 * Input renderers are not node renderers and are never called directly
	 * by the Loom engine. They are instantiated and called by FieldRenderer only.
	 * This class intentionally does not extend AbstractRenderer or implement
	 * RendererInterface — that interface contract must not apply here.
	 */
	abstract class AbstractInputRenderer {
		
		use EscapesTrait;
		
		/**
		 * Render the input element.
		 * Receives pre-resolved values from FieldRenderer so input renderers
		 * do not need to repeat resolution logic.
		 * @param string $id Element id attribute
		 * @param string $name Field name
		 * @param string $value Resolved field value
		 * @param array<string, mixed> $properties Full node properties
		 * @param string $pacField Rendered data-pac-field attribute string
		 * @param string $pacBind Rendered data-pac-bind attribute string
		 * @return string
		 */
		abstract public function renderInput(
			string $id,
			string $name,
			string $value,
			array $properties,
			string $pacField,
			string $pacBind
		): string;
		
		/**
		 * Build a string of HTML validation attributes from node properties.
		 * Only attributes that are present in the properties array are rendered.
		 * @param array<string, mixed> $properties Node properties
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
			if (isset($properties['maxlength']) && is_numeric($properties['maxlength'])) {
				$attrs .= ' maxlength="' . (int)$properties['maxlength'] . '"';
			}
			
			if (isset($properties['minlength']) && is_numeric($properties['minlength'])) {
				$attrs .= ' minlength="' . (int)$properties['minlength'] . '"';
			}
			
			if (isset($properties['min']) && is_numeric($properties['min'])) {
				$attrs .= ' min="' . (float)$properties['min'] . '"';
			}
			
			if (isset($properties['max']) && is_numeric($properties['max'])) {
				$attrs .= ' max="' . (float)$properties['max'] . '"';
			}
			
			if (isset($properties['step'])) {
				// step can be "any" or a number
				$step = $properties['step'];

				if ($step === 'any') {
					$attrs .= ' step="any"';
				} elseif (is_numeric($step)) {
					$attrs .= ' step="' . (float)$step . '"';
				} else {
					$attrs .= ' step="1"';
				}
			}
			
			if (isset($properties['pattern'])) {
				$attrs .= ' pattern="' . $this->e($properties['pattern']) . '"';
			}
			
			if (isset($properties['autocomplete'])) {
				$attrs .= ' autocomplete="' . $this->e($properties['autocomplete']) . '"';
			}
			
			return $attrs;
		}
	}