<?php
	
	namespace Quellabs\Recommender;
	
	use Quellabs\Recommender\Internal\Identifier;
	
	/** The entity a recommendation is for: a persisted member, an anonymous visitor, or a seed product. */
	readonly class Subject {
		
		/** @var SubjectKind Kind of entity */
		public SubjectKind $kind;
		
		/** @var int|null Member or product ID, null for a visitor */
		public ?int $id;
		
		/** @var VisitorContext|null Visitor ratings, null unless the kind is visitor */
		public ?VisitorContext $visitor;
		
		/**
		 * Store the subject fields. Use the static factories to create a subject.
		 * @param SubjectKind $kind Kind of entity
		 * @param int|null $id Member or product ID, null for a visitor
		 * @param VisitorContext|null $visitor Visitor ratings, null unless the kind is visitor
		 */
		private function __construct(SubjectKind $kind, ?int $id, ?VisitorContext $visitor) {
			$this->kind = $kind;
			$this->id = $id;
			$this->visitor = $visitor;
		}
		
		/**
		 * Create a subject for a persisted member.
		 * @param int $member Member ID
		 * @return self
		 * @throws \InvalidArgumentException When the member ID is outside the unsigned 32-bit range
		 */
		public static function member(int $member): self {
			Identifier::assertId($member, 'Member ID');
			return new self(SubjectKind::Member, $member, null);
		}
		
		/**
		 * Create a subject for an anonymous visitor.
		 * @param VisitorContext $visitor Visitor ratings
		 * @return self
		 */
		public static function visitor(VisitorContext $visitor): self {
			return new self(SubjectKind::Visitor, null, $visitor);
		}
		
		/**
		 * Create a subject for a seed product, used by item-to-item queries.
		 * @param int $product Product ID
		 * @return self
		 * @throws \InvalidArgumentException When the product ID is outside the unsigned 32-bit range
		 */
		public static function product(int $product): self {
			Identifier::assertId($product, 'Product ID');
			return new self(SubjectKind::Product, $product, null);
		}
	}