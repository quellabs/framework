<?php
	
	namespace Quellabs\Canvas\Loom\Builder;
	
	/**
	 * Builds a field node — a form field with label and input element.
	 * Use the static factory methods for each input type rather than make() directly.
	 */
	class Field extends AbstractNode {
		
		/**
		 * @param string $name  Field name, used for form submission and WakaPAC binding
		 * @param string $label Field label
		 * @param string $input Input type
		 */
		private function __construct(string $name, string $label, string $input) {
			$this->properties['name']  = $name;
			$this->properties['label'] = $label;
			$this->properties['input'] = $input;
		}
		
		/**
		 * Text input field
		 */
		public static function text(string $name, string $label): static {
			return new static($name, $label, 'text');
		}
		
		/**
		 * Textarea field
		 */
		public static function textarea(string $name, string $label): static {
			return new static($name, $label, 'textarea');
		}
		
		/**
		 * Select dropdown field
		 */
		public static function select(string $name, string $label): static {
			return new static($name, $label, 'select');
		}
		
		/**
		 * Checkbox field
		 */
		public static function checkbox(string $name, string $label): static {
			return new static($name, $label, 'checkbox');
		}
		
		/**
		 * Radio button field
		 */
		public static function radio(string $name, string $label): static {
			return new static($name, $label, 'radio');
		}
		
		/**
		 * Email input field
		 */
		public static function email(string $name, string $label): static {
			return new static($name, $label, 'email');
		}
		
		/**
		 * Telephone input field
		 */
		public static function tel(string $name, string $label): static {
			return new static($name, $label, 'tel');
		}
		
		/**
		 * URL input field
		 */
		public static function url(string $name, string $label): static {
			return new static($name, $label, 'url');
		}
		
		/**
		 * Range slider field
		 */
		public static function range(string $name, string $label): static {
			return new static($name, $label, 'range');
		}
		
		/**
		 * Toggle (on/off switch) field
		 */
		public static function toggle(string $name, string $label): static {
			return new static($name, $label, 'toggle');
		}
		
		/**
		 * Hidden input field — no label, no wrapper, not reactive.
		 * Value is populated from the data array passed to render().
		 */
		public static function hidden(string $name): static {
			return new static($name, '', 'hidden');
		}
		
		/**
		 * Number input field
		 */
		public static function number(string $name, string $label): static {
			return new static($name, $label, 'number');
		}
		
		/**
		 * Mark field as required
		 */
		public function required(): static {
			return $this->set('required', true);
		}
		
		/**
		 * Mark field as disabled
		 */
		public function disabled(): static {
			return $this->set('disabled', true);
		}
		
		/**
		 * Mark field as readonly
		 */
		public function readonly(): static {
			return $this->set('readonly', true);
		}
		
		/**
		 * Set the initial field value.
		 * Not supported on toggle fields — use the data array passed to render() instead.
		 */
		public function value(mixed $value): static {
			if ($this->properties['input'] === 'toggle') {
				throw new \LogicException('Field::value() cannot be used on toggle fields. Pass the initial state via the data array in Loom::render().');
			}
			
			if ($this->properties['input'] === 'hidden') {
				throw new \LogicException('Field::value() cannot be used on hidden fields. Pass the value via the data array in Loom::render().');
			}
			
			return $this->set('value', $value);
		}
		
		/**
		 * Set the placeholder text
		 */
		public function placeholder(string $placeholder): static {
			return $this->set('placeholder', $placeholder);
		}
		
		/**
		 * Set the maximum character length
		 */
		public function maxlength(int $length): static {
			return $this->set('maxlength', $length);
		}
		
		/**
		 * Set the minimum character length
		 */
		public function minlength(int $length): static {
			return $this->set('minlength', $length);
		}
		
		/**
		 * Set the minimum value (number/range)
		 */
		public function min(int|float $min): static {
			return $this->set('min', $min);
		}
		
		/**
		 * Set the maximum value (number/range)
		 */
		public function max(int|float $max): static {
			return $this->set('max', $max);
		}
		
		/**
		 * Set the step value (number/range)
		 */
		public function step(int|float $step): static {
			return $this->set('step', $step);
		}
		
		/**
		 * Set the number of rows (textarea)
		 */
		public function rows(int $rows): static {
			return $this->set('rows', $rows);
		}
		
		/**
		 * Set select options.
		 * Flat associative arrays are normalized to value/label pairs.
		 * Nested associative arrays (for dependent dropdowns) are preserved as-is.
		 * @param array $options
		 * @return static
		 */
		public function options(array $options): static {
			$normalized = [];
			
			foreach ($options as $key => $value) {
				if (is_array($value)) {
					// Nested array — preserve as-is for dependent dropdowns
					$normalized[$key] = $value;
				} else {
					// Flat associative — normalize to value/label pair
					$normalized[] = ['value' => $key, 'label' => $value];
				}
			}
			
			return $this->set('options', $normalized);
		}
		
		/**
		 * Set the autocomplete attribute
		 */
		public function autocomplete(string $value): static {
			return $this->set('autocomplete', $value);
		}
		
		/**
		 * Set a validation pattern
		 */
		public function pattern(string $pattern): static {
			return $this->set('pattern', $pattern);
		}
		
		/**
		 * Override the WakaPAC bind expression
		 */
		public function pacBind(string $bind): static {
			return $this->set('pac_bind', $bind);
		}
		
		/**
		 * Mark this select as dependent on another field.
		 * The options will be filtered based on the selected value
		 * of the parent field.
		 * @param string|null $parentField Name of the field this select depends on
		 * @return static
		 */
		public function dependsOn(?string $parentField): static {
			if ($parentField !== null) {
				return $this->set('depends_on', $parentField);
			}
			
			return $this;
		}
		
		protected function getType(): string {
			return 'field';
		}
		
		/**
		 * Set a hint text shown below the field
		 * @param string $hint
		 * @return static
		 */
		public function hint(string $hint): static {
			return $this->set('hint', $hint);
		}
	}