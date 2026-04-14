<?php
	
	namespace Quellabs\Canvas\Loom\Builder;
	
	/**
	 * Builds a resource node — the top-level form container.
	 */
	class Resource extends AbstractNode {
		
		/**
		 * @param string $id     Form id, also used as WakaPAC component id
		 * @param string $action Form action URL
		 */
		private function __construct(string $id, string $action) {
			$this->properties['id']     = $id;
			$this->properties['action'] = $action;
		}
		
		/**
		 * @param string $id     Form id, also used as WakaPAC component id
		 * @param string $action Form action URL
		 */
		public static function make(string $id, string $action): static {
			return new static($id, $action);
		}
		
		/**
		 * Set the page title shown in the header
		 * @param string $title
		 * @return static
		 */
		public function title(string $title): static {
			return $this->set('title', $title);
		}
		
		/**
		 * Set the form method
		 * @param string $method GET, POST, PUT, PATCH or DELETE
		 * @return static
		 */
		public function method(string $method): static {
			return $this->set('method', $method);
		}
		
		/**
		 * Set the save button label
		 * @param string $label
		 * @return static
		 */
		public function saveLabel(string $label): static {
			return $this->set('save_label', $label);
		}
		
		/**
		 * Disable the save button
		 * @return static
		 */
		public function saveDisabled(): static {
			return $this->set('save_disabled', true);
		}
		
		/**
		 * Add a button to the resource header.
		 * Header buttons are hidden by default and can be shown via WakaPAC messages.
		 * @param Button $button
		 * @return static
		 */
		public function addHeaderButton(Button $button): static {
			$buttons   = $this->get('header_buttons') ?? [];
			$buttons[] = $button;
			return $this->set('header_buttons', $buttons);
		}
		
		/**
		 * Build the node array for Loom::render()
		 * @return array
		 */
		public function build(): array {
			return $this->toArray();
		}
		
		/**
		 * Return node type
		 * @return string
		 */
		protected function getType(): string {
			return 'resource';
		}
		
	}