<?php

	namespace App\Entities;

	use Quellabs\ObjectQuel\Annotations\Orm\Column;
	use Quellabs\ObjectQuel\Annotations\Orm\PrimaryKeyStrategy;
	use Quellabs\ObjectQuel\Annotations\Orm\Table;

	/**
	 * Minimal fixture entity for search() regression tests: a single plain text
	 * column with no FullTextIndex, so search() always resolves to the LIKE
	 * fallback being tested, with none of PostEntity's SoftDelete/lifecycle noise.
	 * @Orm\Table(name="search_term_entities")
	 */
	#[Table(name: "search_term_entities")]
	class SearchTermEntity {

		/**
		 * @Orm\Column(name="id", type="integer", unsigned=true, primary_key=true)
		 * @Orm\PrimaryKeyStrategy(strategy="identity")
		 */
		#[Column(name: "id", type: "integer", unsigned: true, primary_key: true)]
		#[PrimaryKeyStrategy(strategy: "identity")]
		protected ?int $id = null;

		/**
		 * @Orm\Column(name="label", type="string", limit=255)
		 */
		#[Column(name: "label", type: "string", limit: 255)]
		protected string $label = '';

		/**
		 * @Orm\Column(name="note", type="string", limit=255)
		 */
		#[Column(name: "note", type: "string", limit: 255)]
		protected string $note = '';

		public function getId(): ?int {
			return $this->id;
		}

		public function getLabel(): string {
			return $this->label;
		}

		public function getNote(): string {
			return $this->note;
		}
	}
