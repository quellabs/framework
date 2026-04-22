<?php
	
	namespace Quellabs\Canvas\Loom\Renderer\Field;
	
	/**
	 * Renders a checkbox input element.
	 *
	 * CSS classes are defined as protected properties so theme packages
	 * can extend this renderer and override only the class names.
	 */
	class CheckboxRenderer extends AbstractInputRenderer {
		
		/** @var string Checkbox input class */
		protected string $checkboxClass = 'loom-field-checkbox';
		
		/**
		 * @inheritDoc
		 */
		public function renderInput(string $id, string $name, string $value, array $properties, string $pacField, string $pacBind): string {
			$checked = !empty($properties['checked']) ? ' checked' : '';
			$attrs   = $this->buildValidationAttrs($properties);
			
			return "<input type=\"checkbox\" id=\"{$id}\" name=\"{$this->e($name)}\" value=\"{$this->e($value)}\" class=\"{$this->checkboxClass}\"{$checked}{$attrs}{$pacField}{$pacBind}>";
		}
	}