<?php

	namespace Quellabs\ObjectQuel\Tests\Fixtures\RelationshipEntities;

	use Quellabs\ObjectQuel\Annotations\Orm\Table;
	use Quellabs\ObjectQuel\Annotations\Orm\Column;
	use Quellabs\ObjectQuel\Annotations\Orm\PrimaryKeyStrategy;
	use Quellabs\ObjectQuel\Annotations\Orm\ManyToOne;

	/**
	 * Owns a ManyToOne pointing *at* RelPostTagEntity (the bridge), the reverse
	 * direction from RelPostEntity::$postTags: here the bridge is main's own
	 * parent, not a dependent reached via InverseOf. Exercises addForwardBridgeRanges()
	 * eager-joining the bridge directly off 'main' and extending one hop further
	 * through the bridge's own relations.
	 * @Orm\Table(name="rel_audit_logs")
	 */
	class RelAuditLogEntity {
		/**
		 * @Orm\Column(name="id", type="integer", unsigned=true, primary_key=true)
		 * @Orm\PrimaryKeyStrategy(strategy="identity")
		 */
		protected ?int $id = null;

		/**
		 * @Orm\ManyToOne(targetEntity=RelPostTagEntity::class, localColumn="postTagId", fetch="EAGER")
		 */
		public ?RelPostTagEntity $postTag = null;

		/**
		 * @Orm\Column(name="post_tag_id", type="integer")
		 */
		protected ?int $postTagId = null;

		/**
		 * LAZY on purpose: proves addForwardBridgeRanges() skips the extra hop for a
		 * forward relation into a bridge that isn't eager, leaving it to resolve via
		 * the normal proxy — same convention as the child-side LAZY-skip case.
		 * @Orm\ManyToOne(targetEntity=RelPostTagEntity::class, localColumn="lazyPostTagId", fetch="LAZY")
		 */
		public ?RelPostTagEntity $lazyPostTag = null;

		/**
		 * @Orm\Column(name="lazy_post_tag_id", type="integer", nullable=true)
		 */
		protected ?int $lazyPostTagId = null;

		public function getId(): ?int {
			return $this->id;
		}
	}
