<?php

	namespace Quellabs\Recommender\Reconciliation;

	/** The ranking score of one candidate and, for model scorers, its per-source log-odds terms. */
	readonly class ScoredCandidate {

		/** @var float Ranking score; higher ranks first */
		public float $score;

		/** @var array<string, float>|null Log-odds term per source value, null when the scorer has none */
		public ?array $contributions;

		/**
		 * Store the score and contributions.
		 * @param float $score Ranking score
		 * @param array<string, float>|null $contributions Log-odds term per source value, or null
		 */
		public function __construct(float $score, ?array $contributions) {
			$this->score = $score;
			$this->contributions = $contributions;
		}
	}
