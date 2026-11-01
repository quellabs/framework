<?php

	namespace Quellabs\CanvasDoctrine\Tests;

	use Doctrine\ORM\Mapping as ORM;

	/**
	 * Entity fixture for SQLite integration tests.
	 */
	#[ORM\Entity]
	class DoctrineTestEntity {
		#[ORM\Id]
		#[ORM\Column(type: 'integer')]
		private int $id;

		/**
		 * Assigns the identifier.
		 * @param int $id
		 * @return void
		 */
		public function __construct(int $id) { $this->id = $id; }
	}
