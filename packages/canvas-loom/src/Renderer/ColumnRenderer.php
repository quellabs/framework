<?php
	
	namespace Quellabs\Canvas\Loom\Renderer;
	
	use Quellabs\Canvas\Loom\AbstractRenderer;
	use Quellabs\Canvas\Loom\RenderResult;
	
	/**
	 * Renders a single column within a columns layout.
	 * Width is injected by the parent ColumnsRenderer as a percentage.
	 * A column can optionally be marked as a sidebar, rendering a title
	 * and hint text instead of form fields, separated from the content
	 * by a vertical line.
	 *
	 * CSS classes are defined as protected properties so theme packages
	 * can extend this renderer and override only the class names.
	 */
	class ColumnRenderer extends AbstractRenderer {
		
		/** @var string Wrapper div class */
		protected string $wrapperClass = 'loom-column';
		
		/** @var string Sidebar wrapper class */
		protected string $sidebarClass = 'loom-column-sidebar';
		
		/** @var string Sidebar title class */
		protected string $sidebarTitleClass = 'loom-column-sidebar-title';
		
		/** @var string Sidebar hint class */
		protected string $sidebarHintClass = 'loom-column-sidebar-hint';
		
		/**
		 * Render the column
		 * @param array      $properties Node properties from the JSON definition
		 * @param string     $children   Already-rendered HTML of all child nodes
		 * @param array|null $parent     Parent node
		 * @param int        $index      Index of this node within its parent
		 * @return RenderResult
		 */
		public function render(array $properties, string $children, ?array $parent = null, int $index = 0): RenderResult {
			$width   = $properties['width'] ?? null;
			$sidebar = !empty($properties['sidebar']);
			
			// Apply width as inline flex style if provided
			$styleAttr = $width !== null
				? " style=\"flex: 0 0 {$width}%; min-width: 0;\""
				: " style=\"flex: 1; min-width: 0;\"";
			
			if ($sidebar) {
				$title    = $properties['sidebar_title'] ?? '';
				$hint     = $properties['sidebar_hint']  ?? '';
				$class    = $properties['class']         ?? $this->sidebarClass;
				
				if ($title) {
					$titleHtml = "<p class=\"{$this->sidebarTitleClass}\">{$title}</p>";
				} else {
					$titleHtml = '';
				}
				
				if ($hint) {
					$hintHtml = "<p class=\"{$this->sidebarHintClass}\">{$hint}</p>";
				} else {
					$hintHtml = '';
				}
				
				$html = <<<HTML
            <div class="{$class}"{$styleAttr}>
                {$titleHtml}
                {$hintHtml}
            </div>
            HTML;
			} else {
				$class = $properties['class'] ?? $this->wrapperClass;
				
				$html = <<<HTML
            <div class="{$class}"{$styleAttr}>
                {$children}
            </div>
            HTML;
			}
			
			return new RenderResult($html);
		}
	}