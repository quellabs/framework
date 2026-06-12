<?php
	
	namespace Quellabs\Canvas\Loom\Annotations;
	
	use Quellabs\AnnotationReader\AnnotationInterface;
	
	/**
	 * Marks an entity class as a Loom form resource.
	 * Defines the top-level form properties used by EntityReader
	 * to construct a Resource builder via makeFromEntity().
	 *
	 * Usage:
	 *   @Loom\Resource(id="post-form", action="/posts", title="Edit Post", method="POST")
	 */
	class Resource implements AnnotationInterface {
		
		/** Form id, also used as the WakaPAC component id */
		private string $id;
		
		/** Form action URL */
		private string $action;
		
		/** Page title shown in the resource header */
		private string $title;
		
		/** Form method: GET, POST, PUT, PATCH, or DELETE */
		private string $method;
		
		/**
		 * @param array<string, mixed> $parameters
		 */
		public function __construct(array $parameters) {
			$id = $parameters['id'] ?? '';
			$action = $parameters['action'] ?? '';
			$title = $parameters['title'] ?? '';
			$method = $parameters['method'] ?? 'POST';

			$this->id = is_string($id) ? $id : '';
			$this->action = is_string($action) ? $action : '';
			$this->title = is_string($title) ? $title : '';
			$this->method = is_string($method) ? $method : 'POST';
		}
		
		/**
		 * @return array<string, mixed>
		 */
		public function getParameters(): array {
			return [
				'id'     => $this->id,
				'action' => $this->action,
				'title'  => $this->title,
				'method' => $this->method,
			];
		}
		
		/**
		 * @return string
		 */
		public function getId(): string {
			return $this->id;
		}
		
		/**
		 * @return string
		 */
		public function getAction(): string {
			return $this->action;
		}
		
		/**
		 * @return string
		 */
		public function getTitle(): string {
			return $this->title;
		}
		
		/**
		 * @return string
		 */
		public function getMethod(): string {
			return $this->method;
		}
	}