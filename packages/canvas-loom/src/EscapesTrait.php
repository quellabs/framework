<?php
	
	declare(strict_types=1);
	
	namespace Quellabs\Canvas\Loom;
	
	/**
	 * Provides the Loom engine reference and HTML/JS escaping helpers
	 * shared between AbstractRenderer and AbstractInputRenderer.
	 *
	 * These two base classes sit in separate hierarchies — AbstractRenderer
	 * implements RendererInterface (node renderers called by the engine),
	 * while AbstractInputRenderer does not (input renderers called by
	 * FieldRenderer only). A trait is the correct mechanism for sharing
	 * implementation across classes that must not share an interface.
	 */
	trait EscapesTrait {
		
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
		
		/**
		 * Escape a value for safe embedding inside a JavaScript string literal.
		 * Use when interpolating PHP values into inline <script> JS string context,
		 * e.g. wakaPAC('{$this->eJs($id)}', ...).
		 * @param mixed $value
		 * @return string
		 */
		protected function eJs(mixed $value): string {
			if (!is_scalar($value) && $value !== null) {
				return '';
			}
			
			return addcslashes((string)$value, "\\\'\"\r\n\t/");
		}
	}