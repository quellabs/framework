<?php
	
	namespace Quellabs\Canvas\Loom\Builder;
	
	/**
	 * Builds a text node — a read-only label/value pair.
	 * Supports WakaPAC interpolation for reactive values.
	 */
	class Text extends AbstractNode {
		
		/**
		 * @param string $label Label shown above the value
		 * @param string $value Text value, supports WakaPAC interpolation e.g. '{{title}}'
		 */
		private function __construct(string $label, string $value) {
			$this->properties['label'] = $label;
			$this->properties['value'] = $value;
		}
		
		/**
		 * @param string $label Label shown above the value
		 * @param string $value Text value, supports WakaPAC interpolation e.g. '{{title}}'
		 */
		public static function make(string $label, string $value): static {
			return new static($label, $value);
		}
		
		protected function getType(): string {
			return 'text';
		}
	}