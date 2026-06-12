<?php
	
	declare(strict_types=1);
	
	namespace Quellabs\Canvas\Loom;
	
	/**
	 * Base class for all Loom renderers.
	 * Provides access to the Loom engine so renderers can retrieve
	 * the current data context via $this->loom->getData().
	 *
	 * Extend this class when creating custom or theme renderers.
	 */
	abstract class AbstractRenderer implements RendererInterface {
		
		/** The active Loom engine instance */
		protected readonly Loom $loom;
		
		/**
		 * Constructor
		 * @param Loom $loom The active Loom engine instance
		 */
		public function __construct(Loom $loom) {
			$this->loom = $loom;
		}
		
		/**
		 * Escape a value for safe HTML output.
		 * Use on every user-controlled value before inserting into HTML.
		 * @param mixed $value
		 * @return string
		 */
		protected function e(mixed $value): string {
			if (!is_scalar($value) && $value !== null) {
				return '';
			}
			
			return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
		}
	}