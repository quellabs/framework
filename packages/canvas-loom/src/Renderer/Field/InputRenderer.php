<?php
	
	namespace Quellabs\Canvas\Loom\Renderer\Field;
	
	/**
	 * Renders a standard HTML input element.
	 * Covers text, number, email, tel, url, range, date, datetime-local,
	 * time, week, and month input types — all share the same markup pattern.
	 * The HTML type attribute is passed in by FieldRenderer.
	 *
	 * CSS classes are defined as protected properties so theme packages
	 * can extend this renderer and override only the class names.
	 */
	class InputRenderer extends AbstractInputRenderer {
		
		/** @var string Input element class */
		protected string $inputClass = 'loom-field-input';
		
		/**
		 * @inheritDoc
		 */
		public function renderInput(string $id, string $name, string $value, array $properties, string $pacField, string $pacBind): string {
			$type            = $properties['input'] ?? 'text';
			$attrs           = $this->buildValidationAttrs($properties);
			$placeholder     = $properties['placeholder'] ?? '';
			$placeholderAttr = $placeholder ? " placeholder=\"{$this->e($placeholder)}\"" : '';
			
			return "<input type=\"{$this->e($type)}\" id=\"{$id}\" name=\"{$this->e($name)}\" value=\"{$this->e($value)}\" class=\"{$this->inputClass}\"{$placeholderAttr}{$attrs}{$pacField}{$pacBind}>";
		}
	}