<?php
	
	namespace Quellabs\Canvas\Loom\Renderer;
	
	use Quellabs\Canvas\Loom\AbstractRenderer;
	use Quellabs\Canvas\Loom\RenderResult;
	
	/**
	 * Renders a read-only text value with an optional label.
	 * Unlike FieldRenderer, this produces no input element — just
	 * a label and a text value. Supports WakaPAC interpolation
	 * for reactive values.
	 *
	 * CSS classes are defined as protected properties so theme packages
	 * can extend this renderer and override only the class names.
	 */
	class TextRenderer extends AbstractRenderer {
		
		/** @var string Wrapper div class */
		protected string $wrapperClass = 'loom-text';
		
		/** @var string Label element class */
		protected string $labelClass = 'loom-text-label';
		
		/** @var string Value element class */
		protected string $valueClass = 'loom-text-value';
		
		/**
		 * Render the text field
		 * @param array      $properties Node properties from the JSON definition
		 * @param string     $children   Already-rendered HTML of all child nodes
		 * @param array|null $parent     Parent node
		 * @param int        $index      Index of this node within its parent
		 * @return RenderResult
		 */
		public function render(array $properties, string $children, ?array $parent = null, int $index = 0): RenderResult {
			$label = $this->e($properties['label'] ?? '');
			$value = $this->e($properties['value'] ?? '');
			$class = $this->e($properties['class'] ?? $this->wrapperClass);
			
			// Only render a label element when a label is provided
			if ($label) {
				$labelHtml = "<p class=\"{$this->labelClass}\">{$label}</p>";
			} else {
				$labelHtml = '';
			}
			
			// Output html
			$html = <<<HTML
        <div class="{$class}">
            {$labelHtml}
            <p class="{$this->valueClass}">{$value}</p>
        </div>
        HTML;
			
			// Return result
			return new RenderResult($html);
		}
	}