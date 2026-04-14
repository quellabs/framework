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
			$class = $this->e($properties['class'] ?? $this->panelClass);
			
			// id appears in a JS string literal inside data-pac-bind — restrict to safe identifier characters
			if ($id && !preg_match('/^[a-zA-Z0-9_-]+$/', $id)) {
				throw new \InvalidArgumentException("TabRenderer id \"{$id}\" must contain only alphanumerics, hyphens, and underscores.");
			}
			
			// Visible binding references the parent tabs component's activeTab property
			$html = <<<HTML
        <div id="{$id}" class="{$class}" data-pac-bind="visible: activeTab === '{$id}'">
            {$children}
        </div>
        HTML;
			
			return new RenderResult($html);
		}
	}