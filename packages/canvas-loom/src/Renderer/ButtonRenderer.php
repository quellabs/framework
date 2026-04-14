<?php
	
	namespace Quellabs\Canvas\Loom\Renderer;
	
	use Quellabs\Canvas\Loom\AbstractRenderer;
	use Quellabs\Canvas\Loom\RenderResult;
	
	/**
	 * Renders a button element.
	 * The button's action is expressed as a WakaPAC binding expression,
	 * allowing calls to unit functions (e.g. Stdlib.sendMessage) or
	 * methods on the container abstraction (e.g. submit, post).
	 *
	 * CSS classes are defined as protected properties so theme packages
	 * can extend this renderer and override only the class names.
	 */
	class ButtonRenderer extends AbstractRenderer {
		
		/** @var string Base button class */
		protected string $buttonClass = 'loom-button';
		
		/** @var string Primary variant class */
		protected string $primaryClass = 'loom-button-primary';
		
		/** @var string Secondary variant class */
		protected string $secondaryClass = 'loom-button-secondary';
		
		/** @var string Danger variant class */
		protected string $dangerClass = 'loom-button-danger';
		
		/**
		 * Render the button element
		 * @param array      $properties Node properties from the JSON definition
		 * @param string     $children   Already-rendered HTML of all child nodes
		 * @param array|null $parent     Parent node
		 * @param int        $index      Index of this node within its parent
		 * @return RenderResult
		 */
		public function render(array $properties, string $children, ?array $parent = null, int $index = 0): RenderResult {
			$label   = $this->e($properties['label']   ?? '');
			$action  = $properties['action']  ?? null;
			$variant = $properties['variant'] ?? 'primary';
			$type    = $this->e($properties['type']    ?? 'button');
			
			// Build CSS class from base and variant
			$variantClass = match($variant) {
				'secondary' => $this->secondaryClass,
				'danger'    => $this->dangerClass,
				default     => $this->primaryClass,
			};
			
			$class = $this->e($properties['class'] ?? "{$this->buttonClass} {$variantClass}");
			
			// Only render data-pac-bind when an action is provided
			$bindAttr = $action
				? " data-pac-bind=\"click: {$this->e($action)}\""
				: '';
			
			$html = "<button type=\"{$type}\" class=\"{$class}\"{$bindAttr}>{$label}</button>";
			
			return new RenderResult($html);
		}
	}