<?php

	namespace Quellabs\Recommender;

	use Quellabs\Recommender\Internal\Identifier;

	/** Member identifier, an unsigned 32-bit integer. */
	readonly class MemberId {

		/** @var int Wrapped value */
		public int $value;

		/**
		 * Build the value object.
		 * @param int $value Member identifier, an unsigned 32-bit integer
		 * @throws \InvalidArgumentException When the ID is outside the unsigned 32-bit range
		 */
		public function __construct(int $value) {
			Identifier::assertId($value, 'Member ID');

			$this->value = $value;
		}
	}
