<?php
	
	namespace Quellabs\Canvas\Loom;
	
	/**
	 * Base class for all Loom renderers.
	 * Provides access to the Loom engine so renderers can retrieve
	 * the current data context via $this->loom->getData().
	 *
	 * Extend this class when creating custom or theme renderers.
	 */
	abstract class AbstractRenderer implements RendererInterface {
		
		/**
		 * Constructor
		 * @param Loom $loom The active Loom engine instance
		 */
		public function __construct(protected readonly Loom $loom) {}
		
		/**
		 * Escape a value for safe HTML output.
		 * Use on every user-controlled value before inserting into HTML.
		 * @param mixed $value
		 * @return string
		 */
		protected function e(mixed $value): string {
			return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
		}
	}