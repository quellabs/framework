<?php
	
	namespace Quellabs\Canvas\Loom\Annotations;
	
	use Quellabs\AnnotationReader\AnnotationInterface;
	
	/**
	 * Declares a named column group on an entity class.
	 * Multiple @Loom\Column annotations may appear on the same class,
	 * each defining one column by name and width.
	 *
	 * Column annotations are inert until the controller calls
	 * Resource::wrapInColumns() — without that call the form renders flat.
	 *
	 * Usage:
	 *   @Loom\Column(name="main", width=70)
	 *   @Loom\Column(name="sidebar", width=30)
	 */
	class Column implements AnnotationInterface {
		
		/** @var string Column group name, matched against Field::$group */
		private string $name;
		
		/** @var int Column width as a percentage */
		private int $width;
		
		/**
		 * @param array<string, mixed> $parameters
		 */
		public function __construct(array $parameters) {
			$name = $parameters['name'] ?? '';
			$width = $parameters['width'] ?? 50;
			
			$this->name  = is_string($name) ? $name : '';
			$this->width = is_int($width) ? $width : (int)(is_numeric($width) ? $width : 50);
		}
		
		/**
		 * @return array<string, mixed>
		 */
		public function getParameters(): array {
			return [
				'name'  => $this->name,
				'width' => $this->width,
			];
		}
		
		/**
		 * @return string
		 */
		public function getName(): string {
			return $this->name;
		}
		
		/**
		 * @return int
		 */
		public function getWidth(): int {
			return $this->width;
		}
	}