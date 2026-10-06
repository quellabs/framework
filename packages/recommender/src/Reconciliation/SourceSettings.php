<?php

namespace Quellabs\Recommender\Reconciliation;

use Quellabs\Recommender\Internal\Identifier;

/** Per-request settings read by the candidate sources, validated on construction. */
readonly class SourceSettings {

	/** @var int Minimum summed Slope One pair support */
	public int $minSupport;

	/** @var int Minimum ratings for a top-rated candidate */
	public int $topRatedMinRatings;

	/** @var int Minimum neighbour similarity, from 1 to 100 */
	public int $minNeighbourSimilarity;

	/** @var int Maximum neighbours used for user similarity */
	public int $maxNeighbours;

	/**
	 * Store the settings after validating each one.
	 * @param int $minSupport Minimum summed Slope One pair support, at least 1
	 * @param int $topRatedMinRatings Minimum ratings for a top-rated candidate, at least 1
	 * @param int $minNeighbourSimilarity Minimum neighbour similarity, from 1 to 100
	 * @param int $maxNeighbours Maximum neighbours used for user similarity, at least 1
	 * @throws \InvalidArgumentException When a value is outside its allowed range
	 */
	public function __construct(int $minSupport = 1, int $topRatedMinRatings = 2, int $minNeighbourSimilarity = 1, int $maxNeighbours = 100) {
		Identifier::assertAtLeast($minSupport, 1, 'Minimum support');
		Identifier::assertAtLeast($topRatedMinRatings, 1, 'Minimum ratings');
		Identifier::assertInRange($minNeighbourSimilarity, 1, 100, 'Minimum neighbour similarity');
		Identifier::assertAtLeast($maxNeighbours, 1, 'Maximum neighbours');

		$this->minSupport = $minSupport;
		$this->topRatedMinRatings = $topRatedMinRatings;
		$this->minNeighbourSimilarity = $minNeighbourSimilarity;
		$this->maxNeighbours = $maxNeighbours;
	}
}
