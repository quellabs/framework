<?php
	
	namespace Quellabs\Canvas\Loom\Renderer;
	
	use Quellabs\Canvas\Loom\AbstractRenderer;
	use Quellabs\Canvas\Loom\RenderResult;
	
	/**
	 * Renders a single tab panel within a tabs container.
	 * Visibility is controlled by the tabs inline script via the hidden attribute.
	 */
	class TabRenderer extends AbstractRenderer {
		
		/** @var string Tab panel class */
		protected string $panelClass = 'loom-tabs-panel';
		
		/**
		 * Render the tab panel
		 * @param array<string, mixed> $properties
		 * @param string $children
		 * @param array<string, mixed>|null $parent
		 * @param int $index
		 * @return RenderResult
		 */
		public function render(array $properties, string $children, ?array $parent = null, int $index = 0): RenderResult {
			$rawId = $properties['id'] ?? '';
			$id = is_string($rawId) ? $rawId : '';
			$class = $this->e($properties['class'] ?? $this->panelClass);
			
			// id appears in a JS string literal inside data-pac-bind — restrict to safe identifier characters
			if ($id !== '' && !preg_match('/^[a-zA-Z0-9_-]+$/', $id)) {
				throw new \InvalidArgumentException("TabRenderer id \"{$id}\" must contain only alphanumerics, hyphens, and underscores.");
			}
			
			// Non-active panels start hidden — the tabs inline script removes hidden on click.
			// The active tab id is read from the parent Tabs node properties.
			$rawActive = isset($parent['properties']) && is_array($parent['properties']) ? ($parent['properties']['active'] ?? '') : '';
			$activeTab = is_string($rawActive) ? $rawActive : '';
			$hidden = ($id !== $activeTab) ? ' hidden' : '';
			
			// Build HTML
			$html = <<<HTML
        <div id="{$id}" class="{$class}"{$hidden}>
            {$children}
        </div>
        HTML;
			
			// Return HTML
			return new RenderResult($html);
		}
	}