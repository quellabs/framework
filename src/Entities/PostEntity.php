<?php
	
	namespace App\Entities;
	
	use Quellabs\ObjectQuel\Annotations\Orm\Table;
	use Quellabs\ObjectQuel\Annotations\Orm\Column;
	use Quellabs\ObjectQuel\Annotations\Orm\PrimaryKeyStrategy;
	use Quellabs\ObjectQuel\Annotations\Orm\ManyToOne;
	use Quellabs\ObjectQuel\Annotations\Orm\SourceField;
	use Quellabs\ObjectQuel\Annotations\Orm\LifecycleAware;
	use Quellabs\ObjectQuel\Annotations\Orm\PreUpdate;
	use Quellabs\ObjectQuel\Annotations\Orm\FullTextIndex;
	use Quellabs\ObjectQuel\Annotations\Orm\SoftDelete;
	use Quellabs\ObjectQuel\Annotations\Orm\InverseOf;
	use Quellabs\ObjectQuel\Collections\Collection;
	use Quellabs\ObjectQuel\Collections\CollectionInterface;
	use Quellabs\Canvas\Loom\Annotations as Loom;
	
	/**
	 * @Orm\Table(name="posts")
	 * @Orm\FullTextIndex(name="idx_content", columns={"content"})
	 * @Orm\LifecycleAware
	 * @Loom\Resource(id="post-form", action="/posts", title="Edit Post")
	 * @Loom\Column(name="main", width=70)
	 * @Loom\Column(name="sidebar", width=30)
	 */
	class PostEntity {

		/**
		 * @Orm\Column(name="id", type="integer", unsigned=true, primary_key=true)
		 * @Orm\PrimaryKeyStrategy(strategy="identity")
		 * Auto-skipped: primary key
		 */
		protected ?int $id = null;
		
		/**
		 * @Orm\Column(name="title", type="string", limit=255)
		 * @Loom\Field(label="Title", group="main")
		 */
		protected string $title;
		
		/**
		 * @Orm\Column(name="content", type="text")
		 * @Loom\Field(label="Content", input="richtext", group="main")
		 */
		protected string $content;
		
		/**
		 * @Orm\Column(name="published", type="boolean")
		 * @Loom\Field(label="Published", group="sidebar")
		 */
		protected bool $published;
		
		/**
		 * @Orm\Column(name="created_at", type="datetime")
		 * @Loom\Field(label="Created At", group="sidebar", readonly=true)
		 */
		protected \DateTime $createdAt;
		
		/**
		 * @Orm\SourceField(field="test")
		 * Auto-skipped: source field
		 */
		protected string $test;
		
		/**
		 * @Orm\Column(name="test_enum", type="enum", enumType=App\Enums\TestEnum::class)
		 * @Loom\Field(label="Status", group="sidebar")
		 * Options must be supplied by the controller via the data array.
		 */
		protected \App\Enums\TestEnum $TestEnum;
		
		/**
		 * @Orm\Column(name="test_json", type="json")
		 * Not annotated: json fields are omitted by default.
		 */
		protected ?array $testJSON;
		
		/**
		 * @Orm\ManyToOne(targetEntity=UserEntity::class, referencedColumn="id", localColumn="userId", fetch="EAGER")
		 * Not annotated: relations are omitted by default.
		 */
		public ?UserEntity $user;
		
		/**
		 * @Orm\Column(name="user_id", type="integer")
		 * Not annotated: raw FK backing field, not meaningful in a form.
		 * The relation ($user) should be exposed instead when needed.
		 */
		protected ?int $userId = null;
		
		/**
		 * @Orm\Column(name="deleted_at", type="datetime", nullable=true)
		 * @Orm\SoftDelete
		 * Auto-skipped: soft delete sentinel
		 */
		protected ?\DateTime $deletedAt = null;
		
		/**
		 * Get deletedAt
		 * @return \DateTime|null
		 */
		public function getDeletedAt(): ?\DateTime {
			return $this->deletedAt;
		}
		
		/**
		 * Set deletedAt
		 * @param \DateTime|null $deletedAt
		 * @return $this
		 */
		public function setDeletedAt(?\DateTime $deletedAt): self {
			$this->deletedAt = $deletedAt;
			return $this;
		}
		
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
		
		/**
		 * Get TestEnum
		 * @return \App\Enums\TestEnum
		 */
		public function getTestEnum(): \App\Enums\TestEnum {
			return $this->TestEnum;
		}
		
		/**
		 * Set TestEnum
		 * @param \App\Enums\TestEnum $TestEnum
		 * @return $this
		 */
		public function setTestEnum(\App\Enums\TestEnum $TestEnum): self {
			$this->TestEnum = $TestEnum;
			return $this;
		}
		
		public function getTestJSON(): ?array {
			return $this->testJSON;
		}
		
		public function setTestJSON(?array $testJSON): PostEntity {
			$this->testJSON = $testJSON;
			return $this;
		}
	}