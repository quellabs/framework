<?php
	
	namespace Quellabs\Canvas\Loom\Renderer\Field;
	
	/**
	 * Renders a hidden input field.
	 * Bypasses wrapper, label, hint, and WakaPAC binding logic entirely.
	 */
	class HiddenRenderer extends AbstractInputRenderer {
		
		/**
		 * @inheritDoc
		 */
		public function renderInput(string $id, string $name, string $value, array $properties, string $pacField, string $pacBind): string {
			return "<input type=\"hidden\" id=\"{$id}\" name=\"{$this->e($name)}\" value=\"{$this->e($value)}\">";
		}
	}