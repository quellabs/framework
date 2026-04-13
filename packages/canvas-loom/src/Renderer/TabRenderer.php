<?php
	
	namespace Quellabs\Canvas\Loom\Renderer;
	
	use Quellabs\Canvas\Loom\AbstractRenderer;
	use Quellabs\Canvas\Loom\RenderResult;
	
	/**
	 * Renders a single tab panel within a tabs container.
	 * Visibility is controlled by the parent tabs WakaPAC component
	 * via a visible binding on the activeTab property.
	 */
	class TabRenderer extends AbstractRenderer {
		
		/** @var string Tab panel class */
		protected string $panelClass = 'loom-tabs-panel';
		
		/**
		 * Render the tab panel
		 * @param array $properties
		 * @param string $children
		 * @param array|null $parent
		 * @param int $index
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