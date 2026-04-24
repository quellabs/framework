<?php
	
	namespace Quellabs\Canvas\Loom\Renderer\Field;
	
	/**
	 * Renders a richtext field as a <waka-jodit> custom element.
	 *
	 * WakaJodit activates on any element with the tag name waka-jodit.
	 * It creates a proxy <textarea> inside the custom element for form
	 * submission, keeping the field value in sync automatically.
	 *
	 * The element carries its own data-pac-id so WakaJodit can register
	 * it as an independent wakaPAC component. This id is derived from the
	 * field name and scoped to avoid collisions with the parent form's id.
	 *
	 * No data-pac-field or data-pac-bind is emitted — WakaJodit manages
	 * value syncing internally and does not participate in the parent
	 * form's reactive abstraction.
	 *
	 * The accompanying IIFE initialises the component with hydrate: false
	 * since Jodit populates the editor from the element's text content,
	 * not from a data-pac-state attribute.
	 */
	class RichtextRenderer extends AbstractInputRenderer {
		
		/**
		 * Render the <waka-jodit> custom element and its wakaPAC initialisation script.
		 *
		 * @param string $id Element id — also used as the data-pac-id
		 * @param string $name Field name for form submission
		 * @param string $value Initial HTML content, placed as the element's text content
		 * @param array $properties Node properties (disabled/readonly honoured)
		 * @param string $pacField Not used — richtext does not participate in parent form bindings
		 * @param string $pacBind Not used — richtext does not participate in parent form bindings
		 * @return string            HTML only — script is returned separately via renderWithScript()
		 */
		public function renderInput(
			string $id,
			string $name,
			string $value,
			array  $properties,
			string $pacField,
			string $pacBind
		): string {
			// renderInput() is part of the AbstractInputRenderer contract but richtext
			// needs to emit a script block too. FieldRenderer detects the richtext type
			// and calls renderWithScript() instead, which returns both parts.
			// This method is here only to satisfy the interface.
			return $this->buildElement($id, $name, $value, $properties);
		}
		
		/**
		 * Returns both the HTML element and the wakaPAC initialisation script.
		 * Called directly by FieldRenderer for richtext fields, bypassing the
		 * standard renderInput() → RenderResult(html) path.
		 *
		 * @param string $id
		 * @param string $name
		 * @param string $value
		 * @param array $properties
		 * @return array{html: string, script: string}
		 */
		public function renderWithScript(string $id, string $name, string $value, array $properties): array {
			return [
				'html'   => $this->buildElement($id, $name, $value, $properties),
				'script' => $this->buildScript($id),
			];
		}
		
		/**
		 * Build the <waka-jodit> element.
		 * The initial value is placed as the element's text content so WakaJodit
		 * can seed the editor on initialisation. HTML content is not escaped here
		 * because Jodit expects raw HTML — the element is a richtext container,
		 * not a plain text node.
		 * @param string $id
		 * @param string $name
		 * @param string $value
		 * @param array $properties
		 * @return string
		 */
		protected function buildElement(string $id, string $name, string $value, array $properties): string {
			$nameAttr = ' name="' . $this->e($name) . '"';
			$disabledAttr = !empty($properties['disabled']) ? ' disabled' : '';
			$readonlyAttr = !empty($properties['readonly']) ? ' data-readonly' : '';
			
			return "<waka-jodit data-pac-id=\"{$id}\"{$nameAttr}{$disabledAttr}{$readonlyAttr}>{$value}</waka-jodit>";
		}
		
		/**
		 * Build the wakaPAC initialisation IIFE for this richtext component.
		 * hydrate: false because Jodit reads its initial content from the element,
		 * not from data-pac-state.
		 * @param string $id
		 * @return string
		 */
		protected function buildScript(string $id): string {
			return <<<JS
(function() {
    wakaPAC('{$id}', {}, { hydrate: false });
})();
JS;
		}
	}