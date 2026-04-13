<?php
	
	namespace Quellabs\Canvas\Loom\Renderer;
	
	use Quellabs\Canvas\Loom\RenderResult;
	use Quellabs\Canvas\Loom\RendererInterface;
	
	/**
	 * Renders a single tab panel within a tabs container.
	 * Visibility is controlled by the parent tabs WakaPAC component
	 * via a visible binding on the activeTab property.
	 */
	class TabRenderer implements RendererInterface {
		
		/** @var string Tab panel class */
		protected string $panelClass = 'loom-tabs-panel';
		
		/**
		 * Render the tab panel
		 * @param array  $properties Node properties from the JSON definition
		 * @param string $children   Already-rendered HTML of all child nodes
		 * @param array|null $parent Parent node
		 * @param int $index         Index of this node within its parent
		 * @return RenderResult
		 */
		public function render(array $properties, string $children, ?array $parent = null, int $index = 0): RenderResult {
			$id    = $properties['id']    ?? '';
			$class = $properties['class'] ?? $this->panelClass;
			
			// Visible binding references the parent tabs component's activeTab property
			$html = <<<HTML
        <div id="{$id}" class="{$class}" data-pac-bind="visible: activeTab === '{$id}'">
            {$children}
        </div>
        HTML;
			
			return new RenderResult($html);
		}
	}