<?php
	
	namespace Quellabs\Canvas\Loom\Renderer\Field;
	
	/**
	 * Renders a radio button input element.
	 *
	 * CSS classes are defined as protected properties so theme packages
	 * can extend this renderer and override only the class names.
	 */
	class RadioRenderer extends AbstractInputRenderer {
		
		/** @var string Radio input class */
		protected string $radioClass = 'loom-field-radio';
		
		/**
		 * @inheritDoc
		 */
		public function renderInput(string $id, string $name, string $value, array $properties, string $pacField, string $pacBind): string {
			$checked = !empty($properties['checked']) ? ' checked' : '';
			$attrs   = $this->buildValidationAttrs($properties);
			
			return "<input type=\"radio\" id=\"{$id}\" name=\"{$this->e($name)}\" value=\"{$this->e($value)}\" class=\"{$this->radioClass}\"{$checked}{$attrs}{$pacField}{$pacBind}>";
		}
	}