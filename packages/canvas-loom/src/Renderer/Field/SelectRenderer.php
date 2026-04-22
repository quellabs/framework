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
		 * @inheritDoc
		 */
		public function renderInput(string $id, string $name, string $value, array $properties, string $pacField, string $pacBind): string {
			$attrs    = $this->buildValidationAttrs($properties);
			$selected = $this->resolveValue($name, $properties);
			
			// Dependent dropdown — options driven by WakaPAC foreach binding on the select
			if (isset($properties['foreach_expression'])) {
				$expression   = $properties['foreach_expression'];
				$combinedBind = " data-pac-bind=\"foreach: {$expression}, value: {$name}\"";
				
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
			
			$options = '';
			
			foreach ($optionsData as $option) {
				// Support both flat strings and value/label pairs
				$optValue     = is_array($option) ? $option['value'] : $option;
				$optLabel     = is_array($option) ? $option['label'] : $option;
				$selectedAttr = $optValue == $selected ? ' selected' : '';
				$options     .= "<option value=\"{$this->e($optValue)}\"{$selectedAttr}>{$this->e($optLabel)}</option>\n";
			}
			
			return <<<HTML
    <select id="{$id}" name="{$this->e($name)}" class="{$this->selectClass}"{$attrs}{$pacField}{$pacBind}>
        {$options}
    </select>
    HTML;
		}
	}