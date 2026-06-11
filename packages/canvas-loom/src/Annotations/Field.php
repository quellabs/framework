<?php
	
	namespace Quellabs\Canvas\Loom\Annotations;
	
	use Quellabs\AnnotationReader\AnnotationInterface;
	
	/**
	 * Marks an entity property as a Loom form field.
	 * The ORM column type drives the input type by default; use input= to override.
	 * Properties without this annotation are excluded from the rendered form.
	 *
	 * Usage:
	 *   @Loom\Field(label="Title")
	 *   @Loom\Field(label="Content", input="richtext", editor="jodit", group="main")
	 *   @Loom\Field(label="Published", group="sidebar", required=true)
	 */
	class Field implements AnnotationInterface {
		
		/** @var string Field label shown above the input */
		private string $label;
		
		/**
		 * @var string|null Input type override.
		 * When null, EntityReader derives the type from the ORM column type.
		 * Valid values mirror the Field builder factory methods:
		 * text, textarea, select, checkbox, radio, date, datetime-local,
		 * time, week, month, email, tel, url, range, toggle, hidden,
		 * richtext, file, number
		 */
		private ?string $input;
		
		/**
		 * @var string|null Column group name.
		 * Must match the name= of a @Loom\Column annotation on the class.
		 * When null the field is ungrouped and renders flat regardless of
		 * whether wrapInColumns() is called.
		 */
		private ?string $group;
		
		/** @var bool Whether the field is required */
		private bool $required;
		
		/** @var bool Whether the field is readonly */
		private bool $readonly;
		
		/** @var bool Whether the field is disabled */
		private bool $disabled;
		
		/** @var string|null Hint text shown below the input */
		private ?string $hint;
		
		/** @var string|null Placeholder text */
		private ?string $placeholder;
		
		/** @var int|null Number of rows for textarea fields */
		private ?int $rows;
		
		/**
		 * @var string|null Richtext editor name.
		 * Only used when input="richtext". Valid values: jodit, tinymce, ckeditor4, ckeditor5.
		 */
		private ?string $editor;
		
		/**
		 * @param array<string, mixed> $parameters
		 */
		public function __construct(array $parameters) {
			$this->label       = $parameters['label']       ?? '';
			$this->input       = $parameters['input']       ?? null;
			$this->group       = $parameters['group']       ?? null;
			$this->required    = (bool)($parameters['required']    ?? false);
			$this->readonly    = (bool)($parameters['readonly']    ?? false);
			$this->disabled    = (bool)($parameters['disabled']    ?? false);
			$this->hint        = $parameters['hint']        ?? null;
			$this->placeholder = $parameters['placeholder'] ?? null;
			$this->rows        = isset($parameters['rows']) ? (int)$parameters['rows'] : null;
			$this->editor      = $parameters['editor']      ?? null;
		}
		
		/**
		 * @return array<string, mixed>
		 */
		public function getParameters(): array {
			return [
				'label'       => $this->label,
				'input'       => $this->input,
				'group'       => $this->group,
				'required'    => $this->required,
				'readonly'    => $this->readonly,
				'disabled'    => $this->disabled,
				'hint'        => $this->hint,
				'placeholder' => $this->placeholder,
				'rows'        => $this->rows,
				'editor'      => $this->editor,
			];
		}
		
		/**
		 * @return string
		 */
		public function getLabel(): string {
			return $this->label;
		}
		
		/**
		 * @return string|null
		 */
		public function getInput(): ?string {
			return $this->input;
		}
		
		/**
		 * @return string|null
		 */
		public function getGroup(): ?string {
			return $this->group;
		}
		
		/**
		 * @return bool
		 */
		public function isRequired(): bool {
			return $this->required;
		}
		
		/**
		 * @return bool
		 */
		public function isReadonly(): bool {
			return $this->readonly;
		}
		
		/**
		 * @return bool
		 */
		public function isDisabled(): bool {
			return $this->disabled;
		}
		
		/**
		 * @return string|null
		 */
		public function getHint(): ?string {
			return $this->hint;
		}
		
		/**
		 * @return string|null
		 */
		public function getPlaceholder(): ?string {
			return $this->placeholder;
		}
		
		/**
		 * @return int|null
		 */
		public function getRows(): ?int {
			return $this->rows;
		}
		
		/**
		 * @return string|null
		 */
		public function getEditor(): ?string {
			return $this->editor;
		}
	}