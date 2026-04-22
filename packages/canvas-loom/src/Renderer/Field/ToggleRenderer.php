<?php
	
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
		 * @inheritDoc
		 */
		public function renderInput(string $id, string $name, string $value, array $properties, string $pacField, string $pacBind): string {
			// Resolve checked state from data array first, fall back to properties['checked']
			$data          = $this->loom->getData();
			$resolvedValue = (!empty($data) && $name && array_key_exists($name, $data)) ? $data[$name] : ($properties['checked'] ?? false);
			$checked       = $resolvedValue ? ' checked' : '';
			$disabled      = !empty($properties['disabled']);
			$readonly      = !empty($properties['readonly']);
			$pacBind       = $properties['pac_bind'] ?? "checked: {$name}";
			
			// disabled takes priority — a disabled toggle is fully inert, no submission needed
			if ($disabled) {
				return <<<HTML
<label class="loom-toggle" for="{$id}">
    <input type="checkbox" id="{$id}" class="loom-toggle-input"{$checked} {$pacField} data-pac-bind="{$this->e($pacBind)}" disabled>
    <span class="loom-toggle-track" aria-hidden="true">
        <span class="loom-toggle-thumb"></span>
    </span>
</label>
HTML;
			}
			
			// readonly: visually disabled but value still submits via a hidden input
			// (browser ignores readonly on checkboxes; disabled checkboxes don't submit)
			if ($readonly) {
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
			
			// Normal toggle
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