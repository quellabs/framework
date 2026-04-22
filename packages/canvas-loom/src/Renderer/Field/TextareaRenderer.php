<?php
	
	namespace Quellabs\Canvas\Loom\Renderer\Field;
	
	/**
	 * Renders a textarea element.
	 *
	 * CSS classes are defined as protected properties so theme packages
	 * can extend this renderer and override only the class names.
	 */
	class TextareaRenderer extends AbstractInputRenderer {
		
		/** @var string Textarea element class */
		protected string $textareaClass = 'loom-field-textarea';
		
		/**
		 * @inheritDoc
		 */
		public function renderInput(string $id, string $name, string $value, array $properties, string $pacField, string $pacBind): string {
			$attrs           = $this->buildValidationAttrs($properties);
			$placeholder     = $properties['placeholder'] ?? '';
			$placeholderAttr = $placeholder ? " placeholder=\"{$this->e($placeholder)}\"" : '';
			$rows            = (int) ($properties['rows'] ?? 4);
			
			return "<textarea id=\"{$id}\" name=\"{$this->e($name)}\" rows=\"{$rows}\" class=\"{$this->textareaClass}\"{$placeholderAttr}{$attrs}{$pacField}{$pacBind}>{$this->e($value)}</textarea>";
		}
	}