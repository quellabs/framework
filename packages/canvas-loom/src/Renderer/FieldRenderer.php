<?php
	
	namespace Quellabs\Canvas\Loom\Renderer;
	
	use Quellabs\Canvas\Loom\AbstractRenderer;
	use Quellabs\Canvas\Loom\RenderResult;
	use Quellabs\Canvas\Loom\Renderer\Field\AbstractInputRenderer;
	use Quellabs\Canvas\Loom\Renderer\Field\CheckboxRenderer;
	use Quellabs\Canvas\Loom\Renderer\Field\HiddenRenderer;
	use Quellabs\Canvas\Loom\Renderer\Field\InputRenderer;
	use Quellabs\Canvas\Loom\Renderer\Field\RadioRenderer;
	use Quellabs\Canvas\Loom\Renderer\Field\SelectRenderer;
	use Quellabs\Canvas\Loom\Renderer\Field\TextareaRenderer;
	use Quellabs\Canvas\Loom\Renderer\Field\ToggleRenderer;
	
	/**
	 * Dispatches field rendering to a per-type input renderer.
	 * Handles the shared wrapper, label, hint, and WakaPAC binding attributes,
	 * then delegates the actual input element to the appropriate renderer in
	 * the Renderer/Field/ subdirectory.
	 *
	 * To customise a specific input type, extend the corresponding renderer in
	 * Renderer/Field/ and register it via Loom::register(). To customise the
	 * wrapper or label, extend this class and override the class properties.
	 */
	class FieldRenderer extends AbstractRenderer {
		
		/** @var string Wrapper div class */
		protected string $wrapperClass = 'loom-field';
		
		/** @var string Label element class */
		protected string $labelClass = 'loom-field-label';
		
		/** @var string Hint class */
		protected string $hintClass = 'loom-field-hint';
		
		/**
		 * Input renderer instances, keyed by input type.
		 * Lazily instantiated and cached on first use.
		 * @var array<string, AbstractInputRenderer>
		 */
		private array $inputRenderers = [];
		
		/**
		 * Map of input type → renderer class.
		 * Types sharing the same markup pattern map to InputRenderer.
		 * Override entries in a subclass to swap in custom renderers.
		 * @var array<string, class-string<AbstractInputRenderer>>
		 */
		protected array $inputRendererMap = [
			'hidden'         => HiddenRenderer::class,
			'text'           => InputRenderer::class,
			'number'         => InputRenderer::class,
			'email'          => InputRenderer::class,
			'tel'            => InputRenderer::class,
			'url'            => InputRenderer::class,
			'range'          => InputRenderer::class,
			'date'           => InputRenderer::class,
			'datetime-local' => InputRenderer::class,
			'time'           => InputRenderer::class,
			'week'           => InputRenderer::class,
			'month'          => InputRenderer::class,
			'textarea'       => TextareaRenderer::class,
			'select'         => SelectRenderer::class,
			'checkbox'       => CheckboxRenderer::class,
			'radio'          => RadioRenderer::class,
			'toggle'         => ToggleRenderer::class,
		];
		
		/**
		 * @inheritDoc
		 */
		public function render(array $properties, string $children, ?array $parent = null, int $index = 0): RenderResult {
			$type = $properties['input'] ?? 'text';
			
			// Hidden fields bypass the wrapper, label, hint, and WakaPAC binding entirely —
			// they are invisible and have no interactive state to track
			if ($type === 'hidden') {
				$name = $properties['name'] ?? '';
				$id = $this->e($properties['id'] ?? $name);
				$value = $this->resolveValue($name, $properties);
				$html = $this->getInputRenderer('hidden')->renderInput($id, $name, $value, $properties, '', '');
				return new RenderResult($html);
			}
			
			// All other types go through the full wrapper/label/hint pipeline
			return $this->renderDefault($properties);
		}
		
		/**
		 * Render a standard visible field with label, input, and optional hint.
		 * @param array $properties
		 * @return RenderResult
		 */
		protected function renderDefault(array $properties): RenderResult {
			$name = $properties['name'] ?? '';
			$type = $properties['input'] ?? 'text';
			$label = $this->e($properties['label'] ?? '');
			$class = $this->e($properties['class'] ?? $this->wrapperClass);
			$id = $this->e($properties['id'] ?? $name);
			
			// Data array passed to Loom::render() takes precedence over any value
			// set on the builder — this is how server-side data populates the form
			$value = $this->resolveValue($name, $properties);
			
			// data-pac-field marks the element as a WakaPAC-managed field.
			// data-pac-bind wires the field value into the reactive abstraction.
			// Both default to conventions based on the field name but can be
			// overridden entirely via properties when the default isn't appropriate.
			$pacField = $properties['pac_field'] ?? 'data-pac-field';
			$pacBind = $properties['pac_bind'] ?? ($type === 'toggle' ? "checked: {$name}" : "value: {$name}");
			$pacFieldAttr = $pacField ? " {$pacField}" : '';
			$pacBindAttr = $pacBind ? " data-pac-bind=\"{$pacBind}\"" : '';
			
			// Label is omitted entirely when not provided rather than rendering
			// an empty element, so CSS :empty rules and screen readers aren't affected
			if ($label) {
				$labelHtml = "<label for=\"{$id}\" class=\"{$this->labelClass}\">{$label}</label>";
			} else {
				$labelHtml = '';
			}
			
			// Hint is optional — only rendered when explicitly set on the field
			if (isset($properties['hint'])) {
				$hintHtml = "<p class=\"{$this->hintClass}\">{$this->e($properties['hint'])}</p>";
			} else {
				$hintHtml = '';
			}
			
			// Delegate the actual input element to the type-specific renderer.
			// pac attributes are passed pre-rendered so each renderer doesn't
			// need to re-implement the same attribute construction logic.
			$inputHtml = $this->getInputRenderer($type)->renderInput($id, $name, $value, $properties, $pacFieldAttr, $pacBindAttr);
			
			// Build html
			$html = <<<HTML
        <div class="{$class}">
            {$labelHtml}
            {$inputHtml}
            {$hintHtml}
        </div>
        HTML;
			
			// Return result
			return new RenderResult($html);
		}
		
		/**
		 * Resolve or instantiate the input renderer for the given type.
		 * Falls back to InputRenderer for unrecognised types.
		 * @param string $type
		 * @return AbstractInputRenderer
		 */
		private function getInputRenderer(string $type): AbstractInputRenderer {
			if (!isset($this->inputRenderers[$type])) {
				$class = $this->inputRendererMap[$type] ?? InputRenderer::class;
				$this->inputRenderers[$type] = new $class($this->loom);
			}
			
			return $this->inputRenderers[$type];
		}
		
		/**
		 * Resolve the field value from the data array or fall back to the node definition.
		 * @param string $name Field name, used as path into the data array
		 * @param array $properties Node properties
		 * @return string
		 */
		private function resolveValue(string $name, array $properties): string {
			$data = $this->loom->getData();
			
			if (!empty($data) && $name) {
				$value = $this->getNestedValue($data, $name);
				
				if ($value !== null) {
					return (string)$value;
				}
			}
			
			return (string)($properties['value'] ?? '');
		}
		
		/**
		 * Get a nested value from an array using dot and bracket notation.
		 * @param array $data
		 * @param string $path
		 * @return mixed
		 */
		private function getNestedValue(array $data, string $path): mixed {
			$parts = preg_split('/[.\[\]]+/', $path, -1, PREG_SPLIT_NO_EMPTY);
			$current = $data;
			
			foreach ($parts as $part) {
				if (!isset($current[$part])) {
					return null;
				}
				
				$current = $current[$part];
			}
			
			return $current;
		}
	}