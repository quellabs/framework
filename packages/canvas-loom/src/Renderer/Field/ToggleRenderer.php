<?php
	
	declare(strict_types=1);
	
	namespace Quellabs\Canvas\Loom\Renderer\Field;
	
	/**
	 * Renders a toggle switch (on/off).
	 * Uses a hidden checkbox + styled label pair. The pac_bind uses
	 * "checked: name" so WakaPAC maps the boolean state correctly.
	 *
	 * Disabled toggles are fully inert — no form submission.
	 * Readonly toggles are visually disabled but submit their value via a
	 * hidden input, because browsers ignore readonly on checkboxes and
	 * disabled checkboxes do not submit.
	 */
	class ToggleRenderer extends AbstractInputRenderer {
		
		
		/**
		 * Render the input element.
		 * Receives pre-resolved values from FieldRenderer so input renderers
		 * do not need to repeat resolution logic.
		 * @param string $id Element id attribute
		 * @param string $name Field name
		 * @param string $value Resolved field value
		 * @param array<string, mixed> $properties Full node properties
		 * @param string $pacField Rendered data-pac-field attribute string
		 * @param string $pacBind Rendered data-pac-bind attribute string
		 * @return string
		 */
		public function renderInput(string $id, string $name, string $value, array $properties, string $pacField, string $pacBind): string {
			$data = $this->loom->getData();
			
			if (!empty($data) && $name && array_key_exists($name, $data)) {
				$resolvedValue = (bool)$data[$name];
			} else {
				$resolvedValue = (bool)($properties['checked'] ?? false);
			}
			
			if (isset($properties['pac_bind']) && is_string($properties['pac_bind'])) {
				$pacBind = $properties['pac_bind'];
			} else {
				$pacBind = "checked: {$name}";
			}
			
			$checked = $resolvedValue ? ' checked' : '';
			
			if (!empty($properties['disabled'])) {
				// disabled takes priority — a disabled toggle is fully inert, no submission needed
				return $this->renderDisabled($id, $pacField, $pacBind, $checked);
			} elseif (!empty($properties['readonly'])) {
				// readonly: visually disabled but value still submits via a hidden input
				// (browser ignores readonly on checkboxes; disabled checkboxes don't submit)
				return $this->renderReadonly($id, $name, $pacField, $pacBind, $checked, $resolvedValue);
			} else {
				// Normal render
				return $this->renderNormal($id, $name, $pacField, $pacBind, $checked);
			}
		}
		
		/**
		 * Render a disabled toggle — fully inert, no form submission.
		 * @param string $id Element id attribute
		 * @param string $pacField Rendered data-pac-field attribute string
		 * @param string $pacBind Resolved pac_bind expression
		 * @param string $checked HTML checked attribute string, or empty
		 * @return string
		 */
		private function renderDisabled(string $id, string $pacField, string $pacBind, string $checked): string {
			return <<<HTML
<label class="loom-toggle" for="{$id}">
    <input type="checkbox" id="{$id}" class="loom-toggle-input"{$checked} {$pacField} data-pac-bind="{$this->e($pacBind)}" disabled>
    <span class="loom-toggle-track" aria-hidden="true">
        <span class="loom-toggle-thumb"></span>
    </span>
</label>
HTML;
		}
		
		/**
		 * Render a readonly toggle — visually disabled but value submits via hidden input.
		 * (Browsers ignore readonly on checkboxes; disabled checkboxes do not submit.)
		 * @param string $id Element id attribute
		 * @param string $name Field name
		 * @param string $pacField Rendered data-pac-field attribute string
		 * @param string $pacBind Resolved pac_bind expression
		 * @param string $checked HTML checked attribute string, or empty
		 * @param bool $resolvedValue Resolved boolean checked state
		 * @return string
		 */
		private function renderReadonly(string $id, string $name, string $pacField, string $pacBind, string $checked, bool $resolvedValue): string {
			$hiddenValue = $resolvedValue ? '1' : '0';
			
			return <<<HTML
<label class="loom-toggle" for="{$id}">
    <input type="checkbox" id="{$id}" class="loom-toggle-input"{$checked} {$pacField} data-pac-bind="{$this->e($pacBind)}" disabled>
    <span class="loom-toggle-track" aria-hidden="true">
        <span class="loom-toggle-thumb"></span>
    </span>
</label>
<input type="hidden" name="{$this->e($name)}" value="{$hiddenValue}">
HTML;
		}
		
		/**
		 * Render a normal (interactive) toggle.
		 * @param string $id Element id attribute
		 * @param string $name Field name
		 * @param string $pacField Rendered data-pac-field attribute string
		 * @param string $pacBind Resolved pac_bind expression
		 * @param string $checked HTML checked attribute string, or empty
		 * @return string
		 */
		private function renderNormal(string $id, string $name, string $pacField, string $pacBind, string $checked): string {
			return <<<HTML
<label class="loom-toggle" for="{$id}">
    <input type="checkbox" id="{$id}" name="{$this->e($name)}" class="loom-toggle-input"{$checked}{$pacField} data-pac-bind="{$this->e($pacBind)}">
    <span class="loom-toggle-track" aria-hidden="true">
        <span class="loom-toggle-thumb"></span>
    </span>
</label>
HTML;
		}
	}