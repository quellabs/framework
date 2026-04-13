<?php
	
	namespace Quellabs\Canvas\Loom\Renderer;
	
	use Quellabs\Canvas\Loom\RenderResult;
	use Quellabs\Canvas\Loom\RendererInterface;
	
	/**
	 * Renders a form field with label and wrapper element.
	 * Supports all basic HTML input types: text, textarea, select,
	 * checkbox, radio, and number. Each field is wrapped in a div
	 * and includes a label element.
	 *
	 * WakaPAC binding attributes (data-pac-field, data-pac-bind) are
	 * added automatically based on the field name, and can be overruled
	 * via properties when needed.
	 */
	class FieldRenderer implements RendererInterface {
		
		/**
		 * Render a form field
		 * @param array $properties Node properties from the JSON definition
		 * @param string $children Already-rendered HTML of all child nodes (unused for leaf nodes)
		 * @return RenderResult
		 */
		public function render(array $properties, string $children): RenderResult {
			$name = $properties['name'] ?? '';
			$type = $properties['input'] ?? 'text';
			$label = $properties['label'] ?? '';
			$value = $properties['value'] ?? '';
			$class = $properties['class'] ?? 'loom-field';
			$id = $properties['id'] ?? $name;
			
			// data-pac-field and data-pac-bind are derived from the field name by default,
			// but can be overruled entirely via properties
			$pacField = $properties['pac_field'] ?? 'data-pac-field';
			$pacBind = $properties['pac_bind'] ?? "value: {$name}";
			
			$pacFieldAttr = $pacField ? " {$pacField}" : '';
			$pacBindAttr = $pacBind ? " data-pac-bind=\"{$pacBind}\"" : '';
			
			// Only render a label element when a label is provided
			$labelHtml = $label
				? "<label for=\"{$id}\" class=\"loom-field-label\">{$label}</label>"
				: '';
			
			// Delegate to the appropriate input renderer based on type
			$inputHtml = match ($type) {
				'textarea' => $this->renderTextarea($id, $name, $value, $properties, $pacFieldAttr, $pacBindAttr),
				'select' => $this->renderSelect($id, $name, $properties, $pacFieldAttr, $pacBindAttr),
				'checkbox' => $this->renderCheckbox($id, $name, $value, $properties, $pacFieldAttr, $pacBindAttr),
				'radio' => $this->renderRadio($id, $name, $value, $properties, $pacFieldAttr, $pacBindAttr),
				'number' => $this->renderInput('number', $id, $name, $value, $properties, $pacFieldAttr, $pacBindAttr),
				default => $this->renderInput('text', $id, $name, $value, $properties, $pacFieldAttr, $pacBindAttr),
			};
			
			$html = <<<HTML
        <div class="{$class}">
            {$labelHtml}
            {$inputHtml}
        </div>
        HTML;
			
			return new RenderResult($html);
		}
		
		/**
		 * Render a text or number input element
		 * @param string $type HTML input type attribute value
		 * @param string $id Element id attribute
		 * @param string $name Field name used for form submission and WakaPAC binding
		 * @param string $value Initial field value
		 * @param array $properties Full node properties for validation attributes
		 * @param string $pacField Rendered data-pac-field attribute
		 * @param string $pacBind Rendered data-pac-bind attribute
		 * @return string
		 */
		private function renderInput(string $type, string $id, string $name, string $value, array $properties, string $pacField, string $pacBind): string {
			$placeholder = $properties['placeholder'] ?? '';
			$placeholderAttr = $placeholder ? " placeholder=\"{$placeholder}\"" : '';
			
			$attrs = $this->buildValidationAttrs($properties);
			
			return <<<HTML
        <input type="{$type}" id="{$id}" name="{$name}" value="{$value}"{$placeholderAttr}{$attrs}{$pacField}{$pacBind}>
        HTML;
		}
		
		/**
		 * Render a textarea element
		 * @param string $id Element id attribute
		 * @param string $name Field name used for form submission and WakaPAC binding
		 * @param string $value Initial textarea content
		 * @param array $properties Full node properties for validation attributes
		 * @param string $pacField Rendered data-pac-field attribute
		 * @param string $pacBind Rendered data-pac-bind attribute
		 * @return string
		 */
		private function renderTextarea(string $id, string $name, string $value, array $properties, string $pacField, string $pacBind): string {
			$attrs = $this->buildValidationAttrs($properties);
			$placeholder = $properties['placeholder'] ?? '';
			$placeholderAttr = $placeholder ? " placeholder=\"{$placeholder}\"" : '';
			$rows = $properties['rows'] ?? 4;
			
			return <<<HTML
        <textarea id="{$id}" name="{$name}" rows="{$rows}"{$placeholderAttr}{$attrs}{$pacField}{$pacBind}>{$value}</textarea>
        HTML;
		}
		
		/**
		 * Render a select dropdown with options
		 * Options can be a flat array of strings or an array of
		 * ['value' => '...', 'label' => '...'] objects
		 * @param string $id Element id attribute
		 * @param string $name Field name used for form submission and WakaPAC binding
		 * @param array $properties Full node properties including options and selected value
		 * @param string $pacField Rendered data-pac-field attribute
		 * @param string $pacBind Rendered data-pac-bind attribute
		 * @return string
		 */
		private function renderSelect(string $id, string $name, array $properties, string $pacField, string $pacBind): string {
			$selected = $properties['value'] ?? '';
			
			$attrs = $this->buildValidationAttrs($properties);
			
			$options = '';
			
			foreach ($properties['options'] ?? [] as $option) {
				// Support both flat strings and value/label pairs
				$optValue = is_array($option) ? $option['value'] : $option;
				$optLabel = is_array($option) ? $option['label'] : $option;
				$selectedAttr = $optValue == $selected ? ' selected' : '';
				
				$options .= "<option value=\"{$optValue}\"{$selectedAttr}>{$optLabel}</option>\n";
			}
			
			return <<<HTML
        <select id="{$id}" name="{$name}"{$attrs}{$pacField}{$pacBind}>
            {$options}
        </select>
        HTML;
		}
		
		/**
		 * Render a checkbox input
		 * @param string $id Element id attribute
		 * @param string $name Field name used for form submission and WakaPAC binding
		 * @param string $value Value submitted when the checkbox is checked
		 * @param array $properties Full node properties including checked state
		 * @param string $pacField Rendered data-pac-field attribute
		 * @param string $pacBind Rendered data-pac-bind attribute
		 * @return string
		 */
		private function renderCheckbox(string $id, string $name, string $value, array $properties, string $pacField, string $pacBind): string {
			$checked = !empty($properties['checked']) ? ' checked' : '';
			
			$attrs = $this->buildValidationAttrs($properties);
			
			return <<<HTML
        <input type="checkbox" id="{$id}" name="{$name}" value="{$value}"{$checked}{$attrs}{$pacField}{$pacBind}>
        HTML;
		}
		
		/**
		 * Render a radio button input
		 * @param string $id Element id attribute
		 * @param string $name Field name used for form submission and WakaPAC binding
		 * @param string $value Value submitted when this radio button is selected
		 * @param array $properties Full node properties including checked state
		 * @param string $pacField Rendered data-pac-field attribute
		 * @param string $pacBind Rendered data-pac-bind attribute
		 * @return string
		 */
		private function renderRadio(string $id, string $name, string $value, array $properties, string $pacField, string $pacBind): string {
			$checked = !empty($properties['checked']) ? ' checked' : '';
			$attrs = $this->buildValidationAttrs($properties);
			
			return <<<HTML
        <input type="radio" id="{$id}" name="{$name}" value="{$value}"{$checked}{$attrs}{$pacField}{$pacBind}>
        HTML;
		}
		
		/**
		 * Build a string of HTML validation attributes from node properties.
		 * Only attributes that are present in the properties array are rendered.
		 * @param array $properties Node properties
		 * @return string
		 */
		private function buildValidationAttrs(array $properties): string {
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
				$attrs .= " maxlength=\"{$properties['maxlength']}\"";
			}
			
			if (isset($properties['minlength'])) {
				$attrs .= " minlength=\"{$properties['minlength']}\"";
			}
			
			if (isset($properties['min'])) {
				$attrs .= " min=\"{$properties['min']}\"";
			}
			
			if (isset($properties['max'])) {
				$attrs .= " max=\"{$properties['max']}\"";
			}
			
			if (isset($properties['step'])) {
				$attrs .= " step=\"{$properties['step']}\"";
			}
			
			if (isset($properties['pattern'])) {
				$attrs .= " pattern=\"{$properties['pattern']}\"";
			}
			
			if (isset($properties['autocomplete'])) {
				$attrs .= " autocomplete=\"{$properties['autocomplete']}\"";
			}
			
			return $attrs;
		}
	}