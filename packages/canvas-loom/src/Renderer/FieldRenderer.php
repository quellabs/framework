<?php
	
	namespace Quellabs\Canvas\Loom\Renderer;
	
	use Quellabs\Canvas\Loom\AbstractRenderer;
	use Quellabs\Canvas\Loom\RenderResult;
	use Quellabs\Support\StringInflector;
	
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
	class FieldRenderer extends AbstractRenderer {
		
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
		
		/** @var string Hint class */
		protected string $hintClass = 'loom-field-hint';
		
		/**
		 * Render a form field
		 * @param array $properties
		 * @param string $children
		 * @param array|null $parent
		 * @param int $index
		 * @return RenderResult
		 */
		public function render(array $properties, string $children, ?array $parent = null, int $index = 0): RenderResult {
			$type = $properties['input'] ?? 'text';
			
			if ($type === 'hidden') {
				return $this->renderHidden($properties);
			} else {
				return $this->renderDefault($properties);
			}
		}
		
		/**
		 * Render a hidden input field.
		 * Bypasses all wrapper, label, hint, and WakaPAC binding logic.
		 * @param array $properties
		 * @return RenderResult
		 */
		protected function renderHidden(array $properties): RenderResult {
			$name  = $properties['name'] ?? '';
			$id    = $this->e($properties['id'] ?? $name);
			$value = $this->resolveValue($name, $properties);
			
			return new RenderResult("<input type=\"hidden\" id=\"{$id}\" name=\"{$this->e($name)}\" value=\"{$this->e($value)}\">");
		}
		
		/**
		 * Render a standard visible field with label, input, and optional hint.
		 * @param array $properties
		 * @return RenderResult
		 */
		protected function renderDefault(array $properties): RenderResult {
			$name  = $properties['name'] ?? '';
			$type  = $properties['input'] ?? 'text';
			$label = $this->e($properties['label'] ?? '');
			$class = $this->e($properties['class'] ?? $this->wrapperClass);
			$id    = $this->e($properties['id'] ?? $name);
			
			// Data array takes precedence over value in JSON definition
			$value = $this->resolveValue($name, $properties);
			
			// data-pac-field and data-pac-bind are derived from the field name by default,
			// but can be overruled entirely via properties
			$pacField     = $properties['pac_field'] ?? 'data-pac-field';
			$pacBind      = $properties['pac_bind'] ?? ($type === 'toggle' ? "checked: {$name}" : "value: {$name}");
			$pacFieldAttr = $pacField ? " {$pacField}" : '';
			$pacBindAttr  = $pacBind ? " data-pac-bind=\"{$pacBind}\"" : '';
			
			// Only render a label element when a label is provided
			if ($label) {
				$labelHtml = "<label for=\"{$id}\" class=\"{$this->labelClass}\">{$label}</label>";
			} else {
				$labelHtml = '';
			}
			
			// Hint
			if (isset($properties['hint'])) {
				$hintHtml = "<p class=\"{$this->hintClass}\">{$this->e($properties['hint'])}</p>";
			} else {
				$hintHtml = '';
			}
			
			// Delegate to the appropriate input renderer based on type
			$inputHtml = match ($type) {
				'textarea' => $this->renderTextarea($id, $name, $value, $properties, $pacFieldAttr, $pacBindAttr),
				'select'   => $this->renderSelect($id, $name, $properties, $pacFieldAttr, $pacBindAttr, $pacBind),
				'checkbox' => $this->renderCheckbox($id, $name, $value, $properties, $pacFieldAttr, $pacBindAttr),
				'radio'    => $this->renderRadio($id, $name, $value, $properties, $pacFieldAttr, $pacBindAttr),
				'number'   => $this->renderInput('number', $id, $name, $value, $properties, $pacFieldAttr, $pacBindAttr),
				'toggle'   => $this->renderToggle($id, $name, $properties, $pacFieldAttr),
				default    => $this->renderInput('text', $id, $name, $value, $properties, $pacFieldAttr, $pacBindAttr),
			};
			
			// Output element
			$html = <<<HTML
        <div class="{$class}">
            {$labelHtml}
            {$inputHtml}
            {$hintHtml}
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
			$placeholderAttr = $placeholder ? " placeholder=\"{$this->e($placeholder)}\"" : '';
			
			return "<input type=\"{$this->e($type)}\" id=\"{$id}\" name=\"{$this->e($name)}\" value=\"{$this->e($value)}\" class=\"{$this->inputClass}\"{$placeholderAttr}{$attrs}{$pacField}{$pacBind}>";
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
			$placeholderAttr = $placeholder ? " placeholder=\"{$this->e($placeholder)}\"" : '';
			$rows = (int) ($properties['rows'] ?? 4);
			
			return "<textarea id=\"{$id}\" name=\"{$this->e($name)}\" rows=\"{$rows}\" class=\"{$this->textareaClass}\"{$placeholderAttr}{$attrs}{$pacField}{$pacBind}>{$this->e($value)}</textarea>";
		}
		
		/**
		 * Render a select dropdown with options.
		 * If the field has a foreach_expression property, it renders as a
		 * dependent dropdown driven by a WakaPAC foreach binding.
		 * Otherwise renders as a static dropdown with predefined options.
		 * Options can be a flat array of strings or an array of
		 * ['value' => '...', 'label' => '...'] objects.
		 * @param string $id Element id attribute
		 * @param string $name Field name used for form submission and WakaPAC binding
		 * @param array $properties Full node properties including options and selected value
		 * @param string $pacField Rendered data-pac-field attribute string
		 * @param string $pacBind Rendered data-pac-bind attribute string
		 * @param string $pacBindExpr Raw pac bind expression (before attribute wrapping), used when prepending foreach
		 * @return string
		 */
		private function renderSelect(string $id, string $name, array $properties, string $pacField, string $pacBind, string $pacBindExpr = ''): string {
			$attrs = $this->buildValidationAttrs($properties);
			$selected = $this->resolveValue($name, $properties);
			
			// Dependent dropdown — options driven by WakaPAC foreach binding on the select
			if (isset($properties['foreach_expression'])) {
				$expression = $properties['foreach_expression'];
				
				// Prepend foreach to the raw expression string, then wrap in the attribute.
				// Using the raw expression avoids brittle string manipulation on an already-rendered attribute.
				$combinedBind = " data-pac-bind=\"foreach: {$expression}, {$pacBindExpr}\"";
				
				return <<<HTML
        <select id="{$id}" name="{$name}" class="{$this->selectClass}"{$attrs}{$pacField}{$combinedBind}>
            <option data-pac-bind="value: item.value">{{item.label}}</option>
        </select>
        HTML;
			}
			
			// Resolve options from properties or fall back to data array via pluralized field name
			$optionsData = $properties['options']
				?? $this->loom->getData()[StringInflector::pluralize($name)]
				?? [];
			
			// Static options
			$options = '';
			
			foreach ($optionsData as $option) {
				// Support both flat strings and value/label pairs
				$optValue = is_array($option) ? $option['value'] : $option;
				$optLabel = is_array($option) ? $option['label'] : $option;
				$selectedAttr = $optValue == $selected ? ' selected' : '';
				$options .= "<option value=\"{$this->e($optValue)}\"{$selectedAttr}>{$this->e($optLabel)}</option>\n";
			}
			
			return <<<HTML
    <select id="{$id}" name="{$this->e($name)}" class="{$this->selectClass}"{$attrs}{$pacField}{$pacBind}>
        {$options}
    </select>
    HTML;
		}
		
		/**
		 * Render a toggle switch input.
		 * Uses a hidden checkbox + styled label pair. The pac_bind uses
		 * "checked: name" so WakaPAC maps the boolean state correctly.
		 * Note: pac_bind is intentionally excluded here — the hidden checkbox
		 * carries data-pac-field, and the bind is on the visible label via JS,
		 * so we pass pacBind directly to the checkbox input element.
		 * @param string $id Element id attribute
		 * @param string $name Field name
		 * @param array $properties Full node properties
		 * @param string $pacField Rendered data-pac-field attribute
		 * @return string
		 */
		private function renderToggle(string $id, string $name, array $properties, string $pacField): string {
			// Resolve checked state from data array first, fall back to properties['checked']
			$data          = $this->loom->getData();
			$resolvedValue = (!empty($data) && $name && array_key_exists($name, $data)) ? $data[$name] : ($properties['checked'] ?? false);
			$checked       = $resolvedValue ? ' checked' : '';
			$disabled      = !empty($properties['disabled']) ? ' disabled' : '';
			$pacBind       = $properties['pac_bind'] ?? "checked: {$name}";
			
			// The checkbox is visually hidden; the <label> provides the toggle UI.
			// data-pac-bind goes on the checkbox so WakaPAC binds the boolean value.
			return <<<HTML
<label class="loom-toggle" for="{$id}">
    <input type="checkbox" id="{$id}" name="{$this->e($name)}" class="loom-toggle-input"{$checked}{$disabled}{$pacField} data-pac-bind="{$this->e($pacBind)}">
    <span class="loom-toggle-track" aria-hidden="true">
        <span class="loom-toggle-thumb"></span>
    </span>
</label>
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
			
			return "<input type=\"checkbox\" id=\"{$id}\" name=\"{$this->e($name)}\" value=\"{$this->e($value)}\" class=\"{$this->checkboxClass}\"{$checked}{$attrs}{$pacField}{$pacBind}>";
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
			
			return "<input type=\"radio\" id=\"{$id}\" name=\"{$this->e($name)}\" value=\"{$this->e($value)}\" class=\"{$this->radioClass}\"{$checked}{$attrs}{$pacField}{$pacBind}>";
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
				$attrs .= ' maxlength="' . (int) $properties['maxlength'] . '"';
			}
			
			if (isset($properties['minlength'])) {
				$attrs .= ' minlength="' . (int) $properties['minlength'] . '"';
			}
			
			if (isset($properties['min'])) {
				$attrs .= ' min="' . (int) $properties['min'] . '"';
			}
			
			if (isset($properties['max'])) {
				$attrs .= ' max="' . (int) $properties['max'] . '"';
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
		 * Resolve the field value from the data array or fall back to the JSON definition
		 * @param string $name Field name, used as path into the data array
		 * @param array $properties Node properties
		 * @return string
		 */
		private function resolveValue(string $name, array $properties): string {
			$data = $this->loom->getData();
			
			if (!empty($data) && $name) {
				$value = $this->getNestedValue($data, $name);
				
				if ($value !== null) {
					return (string) $value;
				}
			}
			
			// Fall back to value in JSON definition
			return (string) ($properties['value'] ?? '');
		}
		
		/**
		 * Get a nested value from an array using dot and bracket notation
		 * @param array $data
		 * @param string $path
		 * @return mixed
		 */
		private function getNestedValue(array $data, string $path): mixed {
			$parts = preg_split('/[\.\[\]]+/', $path, -1, PREG_SPLIT_NO_EMPTY);
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