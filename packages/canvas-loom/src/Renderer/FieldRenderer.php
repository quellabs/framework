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
	 *
	 * CSS classes are defined as protected properties so theme packages
	 * can extend this renderer and override only the class names.
	 */
	class FieldRenderer implements RendererInterface {
		
		/** @var string Wrapper div class */
		protected string $wrapperClass = 'loom-field';
		
		/** @var string Label element class */
		protected string $labelClass = 'loom-field-label';
		
		/** @var string Input element class */
		protected string $inputClass = 'loom-field-input';
		
		/** @var string Textarea element class */
		protected string $textareaClass = 'loom-field-textarea';
		
		/** @var string Select element class */
		protected string $selectClass = 'loom-field-select';
		
		/** @var string Checkbox input class */
		protected string $checkboxClass = 'loom-field-checkbox';
		
		/** @var string Radio input class */
		protected string $radioClass = 'loom-field-radio';
		
		/**
		 * Render a form field
		 * @param array $properties
		 * @param string $children
		 * @param array|null $parent
		 * @param int $index
		 * @return RenderResult
		 */
		public function render(array $properties, string $children, ?array $parent = null, int $index = 0): RenderResult {
			$name = $properties['name'] ?? '';
			$type = $properties['input'] ?? 'text';
			$label = $properties['label'] ?? '';
			$value = $properties['value'] ?? '';
			$class = $properties['class'] ?? $this->wrapperClass;
			$id = $properties['id'] ?? $name;
			
			// data-pac-field and data-pac-bind are derived from the field name by default,
			// but can be overruled entirely via properties
			$pacField = $properties['pac_field'] ?? 'data-pac-field';
			$pacBind = $properties['pac_bind'] ?? "value: {$name}";
			
			$pacFieldAttr = $pacField ? " {$pacField}" : '';
			$pacBindAttr = $pacBind ? " data-pac-bind=\"{$pacBind}\"" : '';
			
			// Only render a label element when a label is provided
			$labelHtml = $label
				? "<label for=\"{$id}\" class=\"{$this->labelClass}\">{$label}</label>"
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
			$attrs = $this->buildValidationAttrs($properties);
			$placeholder = $properties['placeholder'] ?? '';
			$placeholderAttr = $placeholder ? " placeholder=\"{$placeholder}\"" : '';
			
			return "<input type=\"{$type}\" id=\"{$id}\" name=\"{$name}\" value=\"{$value}\" class=\"{$this->inputClass}\"{$placeholderAttr}{$attrs}{$pacField}{$pacBind}>";
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
			
			return "<textarea id=\"{$id}\" name=\"{$name}\" rows=\"{$rows}\" class=\"{$this->textareaClass}\"{$placeholderAttr}{$attrs}{$pacField}{$pacBind}>{$value}</textarea>";
		}
		
		/**
		 * Render a select dropdown with options.
		 * Options can be a flat array of strings or an array of
		 * ['value' => '...', 'label' => '...'] objects.
		 * @param string $id Element id attribute
		 * @param string $name Field name used for form submission and WakaPAC binding
		 * @param array $properties Full node properties including options and selected value
		 * @param string $pacField Rendered data-pac-field attribute
		 * @param string $pacBind Rendered data-pac-bind attribute
		 * @return string
		 */
		private function renderSelect(string $id, string $name, array $properties, string $pacField, string $pacBind): string {
			$attrs = $this->buildValidationAttrs($properties);
			$selected = $properties['value'] ?? '';
			$options = '';
			
			foreach ($properties['options'] ?? [] as $option) {
				// Support both flat strings and value/label pairs
				$optValue = is_array($option) ? $option['value'] : $option;
				$optLabel = is_array($option) ? $option['label'] : $option;
				$selectedAttr = $optValue == $selected ? ' selected' : '';
				$options .= "<option value=\"{$optValue}\"{$selectedAttr}>{$optLabel}</option>\n";
			}
			
			return "<select id=\"{$id}\" name=\"{$name}\" class=\"{$this->selectClass}\"{$attrs}{$pacField}{$pacBind}>\n{$options}</select>";
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
			
			return "<input type=\"checkbox\" id=\"{$id}\" name=\"{$name}\" value=\"{$value}\" class=\"{$this->checkboxClass}\"{$checked}{$attrs}{$pacField}{$pacBind}>";
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
			
			return "<input type=\"radio\" id=\"{$id}\" name=\"{$name}\" value=\"{$value}\" class=\"{$this->radioClass}\"{$checked}{$attrs}{$pacField}{$pacBind}>";
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