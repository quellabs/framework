<?php
	
	namespace Quellabs\Canvas\Loom\Annotations;
	
	use Quellabs\AnnotationReader\AnnotationInterface;
	
	/**
	 * Marks an entity property as a Loom form field.
	 * The ORM column type drives the input type by default; use input= to override.
	 * Properties without this annotation are excluded from the rendered form.
	 *
	 * Usage:
	 * @Loom\Field(label="Title")
	 * @Loom\Field(label="Content", input="richtext", editor="jodit", group="main")
	 * @Loom\Field(label="Published", group="sidebar", required=true)
	 */
	class Field implements AnnotationInterface {
		
		/** Field label shown above the input */
		private string $label;
		
		/**
		 * Input type override.
		 * When null, EntityReader derives the type from the ORM column type.
		 * Valid values mirror the Field builder factory methods:
		 * text, textarea, select, checkbox, radio, date, datetime-local,
		 * time, week, month, email, tel, url, range, toggle, hidden,
		 * richtext, file, number
		 */
		private ?string $input;
		
		/**
		 * Column group name.
		 * Must match the name= of a @Loom\Column annotation on the class.
		 * When null the field is ungrouped and renders flat regardless of
		 * whether wrapInColumns() is called.
		 */
		private ?string $group;
		
		/** Whether the field is required */
		private bool $required;
		
		/** Whether the field is readonly */
		private bool $readonly;
		
		/** Whether the field is disabled */
		private bool $disabled;
		
		/** Hint text shown below the input */
		private ?string $hint;
		
		/** Placeholder text */
		private ?string $placeholder;
		
		/** Number of rows for textarea fields */
		private ?int $rows;
		
		/**
		 * Richtext editor name.
		 * Only used when input="richtext". Valid values: jodit, tinymce, ckeditor4, ckeditor5.
		 */
		private ?string $editor;
		
		/**
		 * @var array<string|int, string>|null Explicit select options as value => label pairs.
		 * When set, always takes precedence over enum auto-population.
		 * Syntax: choices={"draft"="Draft", "published"="Published"}
		 */
		private ?array $choices;
		
		/**
		 * @param array<string, mixed> $parameters
		 */
		public function __construct(array $parameters) {
			$label = $parameters['label'] ?? '';
			$input = $parameters['input'] ?? null;
			$group = $parameters['group'] ?? null;
			$hint = $parameters['hint'] ?? null;
			$placeholder = $parameters['placeholder'] ?? null;
			$rows = $parameters['rows'] ?? null;
			$editor = $parameters['editor'] ?? null;
			$choices = $parameters['choices'] ?? null;
			
			/** @var array<int|string, string>|null $typedChoices */
			$typedChoices = (is_array($choices) && $this->isStringMap($choices)) ? $choices : null;
			
			$this->choices = $typedChoices;
			$this->rows = isset($rows) && is_numeric($rows) ? (int)$rows : null;
			$this->editor = is_string($editor) ? $editor : null;
			$this->placeholder = is_string($placeholder) ? $placeholder : null;
			$this->hint = is_string($hint) ? $hint : null;
			$this->label = is_string($label) ? $label : '';
			$this->input = is_string($input) ? $input : null;
			$this->group = is_string($group) ? $group : null;
			$this->required = (bool)($parameters['required'] ?? false);
			$this->readonly = (bool)($parameters['readonly'] ?? false);
			$this->disabled = (bool)($parameters['disabled'] ?? false);
			
		}
		
		/**
		 * Check that all values in the array are strings (for choices validation).
		 * @param array<mixed, mixed> $array
		 * @return bool
		 */
		private function isStringMap(array $array): bool {
			foreach ($array as $v) {
				if (!is_string($v)) {
					return false;
				}
			}
			
			return true;
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
				'choices'     => $this->choices,
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
		
		/**
		 * @return array<string|int, string>|null
		 */
		public function getChoices(): ?array {
			return $this->choices;
		}
	}