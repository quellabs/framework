<?php
	
	namespace Quellabs\Canvas\Loom\Renderer\Field;
	
	/**
	 * Renders a richtext field as a wakaPAC-managed editor custom element.
	 *
	 * Supports Jodit, TinyMCE, CKEditor 4, and CKEditor 5 via their respective
	 * custom element tags. All four share the same wakaPAC plugin API — only the
	 * tag name differs. The editor plugin handles value syncing to the internal
	 * proxy textarea automatically; no data-pac-bind or hidden input is needed.
	 *
	 * The accompanying IIFE initialises the component with hydrate: false since
	 * the editor reads its initial content from the element's text content, not
	 * from a data-pac-state attribute.
	 */
	class RichtextRenderer extends AbstractInputRenderer {
		
		/**
		 * Map of editor identifier to custom element tag name.
		 * @var array<string, string>
		 */
		protected array $editorTags = [
			'jodit'     => 'waka-jodit',
			'tinymce'   => 'waka-tinymce',
			'ckeditor4' => 'waka-ckeditor',
			'ckeditor5' => 'waka-ckeditor',
		];
		
		/**
		 * @inheritDoc
		 * Not used directly — FieldRenderer calls renderWithScript() for richtext fields.
		 */
		public function renderInput(
			string $id,
			string $name,
			string $value,
			array  $properties,
			string $pacField,
			string $pacBind
		): string {
			return $this->buildElement($id, $name, $value, $properties);
		}
		
		/**
		 * Returns both the HTML element and the wakaPAC initialisation script.
		 * Called directly by FieldRenderer, bypassing the standard renderInput() path.
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
		 * Resolve the custom element tag for the requested editor.
		 * Falls back to waka-jodit for unrecognised values.
		 * @param array $properties
		 * @return string
		 */
		protected function resolveTag(array $properties): string {
			$editor = strtolower($properties['editor'] ?? 'jodit');
			return $this->editorTags[$editor] ?? 'waka-jodit';
		}
		
		/**
		 * Build the editor custom element.
		 * HTML content is placed as raw text content — not escaped — because the
		 * editor expects raw HTML as its initial value.
		 * @param string $id
		 * @param string $name
		 * @param string $value
		 * @param array $properties
		 * @return string
		 */
		protected function buildElement(string $id, string $name, string $value, array $properties): string {
			$tag = $this->resolveTag($properties);
			$nameAttr = ' name="' . $this->e($name) . '"';
			$disabledAttr = !empty($properties['disabled']) ? ' disabled' : '';
			$readonlyAttr = !empty($properties['readonly']) ? ' data-readonly' : '';
			
			return "<{$tag} data-pac-id=\"{$id}\"{$nameAttr}{$disabledAttr}{$readonlyAttr}>{$value}</{$tag}>";
		}
		
		/**
		 * Build the wakaPAC initialisation IIFE.
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