<?php
	
	namespace Quellabs\Recommender;
	
	use Quellabs\Recommender\Internal\Identifier;
	
	/** Product identifier, an unsigned 32-bit integer. */
	readonly class ProductId {
		
		/** @var int Wrapped value */
		public int $value;
		
		/**
		 * Build the value object.
		 * @param int $value Product identifier, an unsigned 32-bit integer
		 * @throws \InvalidArgumentException When the ID is outside the unsigned 32-bit range
		 */
		public function __construct(int $value) {
			Identifier::assertId($value, 'Product ID');
			
			$this->value = $value;
		}
		
		/**
		 * Wrap a raw product identifier.
		 * @param int $value Product identifier, an unsigned 32-bit integer
		 * @return self Validated product identifier
		 * @throws \InvalidArgumentException When the ID is outside the unsigned 32-bit range
		 */
		public static function of(int $value): self {
			return new self($value);
		}
	}
