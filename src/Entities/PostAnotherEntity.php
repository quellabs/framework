<?php
	
	namespace App\Entities;
	
	use Quellabs\ObjectQuel\Annotations\Orm\Table;
	use Quellabs\ObjectQuel\Annotations\Orm\Column;
	use Quellabs\ObjectQuel\Annotations\Orm\PrimaryKeyStrategy;
	use Quellabs\ObjectQuel\Annotations\Orm\OneToOne;
	use Quellabs\ObjectQuel\Annotations\Orm\OneToMany;
	use Quellabs\ObjectQuel\Annotations\Orm\ManyToOne;
	use Quellabs\ObjectQuel\Annotations\Orm\LifecycleAware;
	use Quellabs\ObjectQuel\Annotations\Orm\PreUpdate;
	use Quellabs\ObjectQuel\Collections\Collection;
	use Quellabs\ObjectQuel\Collections\CollectionInterface;
	
	/**
	 * @Orm\Table(name="posts")
	 * @Orm\LifecycleAware
	 */
	class PostAnotherEntity {
		
		/**
		 * @Orm\Column(name="id", type="integer", unsigned=true, primary_key=true)
		 * @Orm\PrimaryKeyStrategy(strategy="identity")
		 */
		protected ?int $id = null;
		
		/**
		 * @Orm\Column(name="title", type="string", limit=255)
		 */
		protected string $title;
		
		/**
		 * @Orm\Column(name="content", type="text")
		 */
		protected string $content;
		
		/**
		 * @Orm\Column(name="published", type="boolean")
		 */
		protected bool $published;
		
		/**
		 * @Orm\Column(name="created_at", type="datetime")
		 */
		protected \DateTime $createdAt;
		
		/**
		 * Get id
		 * @return int
		 */
		public function getId(): int {
			return $this->id;
		}
		
		/**
		 * Get title
		 * @return string
		 */
		public function getTitle(): string {
			return $this->title;
		}
		
		/**
		 * Set title
		 * @param string $title
		 * @return $this
		 */
		public function setTitle(string $title): self {
			$this->title = $title;
			return $this;
		}
		
		/**
		 * Get content
		 * @return string
		 */
		public function getContent(): string {
			return $this->content;
		}
		
		/**
		 * Set content
		 * @param string $content
		 * @return $this
		 */
		public function setContent(string $content): self {
			$this->content = $content;
			return $this;
		}
		
		/**
		 * Get published
		 * @return bool
		 */
		public function getPublished(): bool {
			return $this->published;
		}
		
		/**
		 * Set published
		 * @param bool $published
		 * @return $this
		 */
		public function setPublished(bool $published): self {
			$this->published = $published;
			return $this;
		}
		
		/**
		 * Get createdAt
		 * @return \DateTime
		 */
		public function getCreatedAt(): \DateTime {
			return $this->createdAt;
		}
		
		/**
		 * Set createdAt
		 * @param \DateTime $createdAt
		 * @return $this
		 */
		public function setCreatedAt(\DateTime $createdAt): self {
			$this->createdAt = $createdAt;
			return $this;
		}
		
		/**
		 *
		 * @Orm\PreUpdate
		 * @return void
		 */
		public function test(): void {
			$this->setCreatedAt(new \DateTime());
		}
	}
