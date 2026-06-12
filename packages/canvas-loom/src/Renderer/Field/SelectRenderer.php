<?php
	
	namespace Quellabs\Canvas\Loom\Renderer\Field;
	
	use Quellabs\Support\StringInflector;
	
	/**
	 * Renders a select dropdown.
	 * If the field has a foreach_expression property it renders as a dependent
	 * dropdown driven by a WakaPAC foreach binding. Otherwise it renders as a
	 * static dropdown with predefined options.
	 * Options can be a flat array of strings or ['value' => '...', 'label' => '...'] pairs.
	 *
	 * CSS classes are defined as protected properties so theme packages
	 * can extend this renderer and override only the class names.
	 */
	class SelectRenderer extends AbstractInputRenderer {
		
		/** @var string Select element class */
		protected string $selectClass = 'loom-field-select';
		
		/**
		 * Renders a select dropdown.
		 * @param string $id The HTML id attribute for the select element.
		 * @param string $name The field name and HTML name attribute.
		 * @param string $value Unused; current value is resolved via resolveValue().
		 * @param array<string, mixed> $properties Field configuration
		 * @param string $pacField Rendered data-pac-field attribute string, or empty.
		 * @param string $pacBind Rendered data-pac-bind attribute string, or empty.
		 * @return string The rendered <select> HTML.
		 */
		public function renderInput(string $id, string $name, string $value, array $properties, string $pacField, string $pacBind): string {
			$attrs = $this->buildValidationAttrs($properties);
			
			if (isset($properties['foreach_expression'])) {
				return $this->renderDependentSelect($id, $name, $attrs, $pacField, $properties);
			} else {
				return $this->renderStaticSelect($id, $name, $attrs, $pacField, $pacBind, $properties);
			}
		}
		
		/**
		 * Renders a dependent dropdown driven by a WakaPAC foreach binding.
		 *
		 * The foreach_expression property is used as the binding source. Options are
		 * rendered as a single template <option> that WakaPAC repeats for each item,
		 * binding item.value and item.label at runtime.
		 *
		 * @param string $id The HTML id attribute for the select element.
		 * @param string $name The field name, also used as the WakaPAC value binding target.
		 * @param string $attrs Pre-rendered validation attribute string (required, min, max, etc.).
		 * @param string $pacField Rendered data-pac-field attribute string, or empty.
		 * @param array<string, mixed> $properties Field configuration. Must contain foreach_expression.
		 * @return string            The rendered <select> HTML with a WakaPAC foreach template option.
		 */
		protected function renderDependentSelect(string $id, string $name, string $attrs, string $pacField, array $properties): string {
			$rawExpression = $properties['foreach_expression'];
			$expression = is_string($rawExpression) ? $rawExpression : '';
			$combinedBind = " data-pac-bind=\"foreach: {$expression}, value: {$name}\"";
			
			return <<<HTML
        <select id="{$id}" name="{$name}" class="{$this->selectClass}"{$attrs}{$pacField}{$combinedBind}>
            <option data-pac-bind="value: item.value">{{item.label}}</option>
        </select>
        HTML;
		}
		
		/**
		 * Renders a static dropdown with predefined options.
		 *
		 * Options are resolved from properties['options'] first, then from the Loom
		 * data bag using the pluralized field name as the key. Each option can be
		 * either a flat string (used as both value and label) or an associative array
		 * with 'value' and 'label' keys. The currently selected option is determined
		 * by loose equality against $selected.
		 *
		 * @param string $id The HTML id attribute for the select element.
		 * @param string $name The field name and HTML name attribute.
		 * @param string $attrs Pre-rendered validation attribute string (required, min, max, etc.).
		 * @param string $pacField Rendered data-pac-field attribute string, or empty.
		 * @param string $pacBind Rendered data-pac-bind attribute string, or empty.
		 * @param array<string, mixed> $properties Field configuration
		 * @return string The rendered <select> HTML with resolved <option> elements.
		 */
		protected function renderStaticSelect(string $id, string $name, string $attrs, string $pacField, string $pacBind, array $properties): string {
			// The current field value used to mark the matching option as selected
			$selected = $this->resolveValue($name, $properties);
			
			// Resolve options from properties or fall back to data array via pluralized field name
			$rawOptions = $properties['options'] ?? $this->loom->getData()[StringInflector::pluralize($name)] ?? [];
			$optionsData = is_array($rawOptions) ? $rawOptions : [];
			
			// Build options list
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
	}