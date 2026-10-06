<?php

namespace Quellabs\Recommender\Reconciliation;

use Quellabs\Recommender\Internal\Identifier;

/** Per-request reconciliation settings: source settings, plus the depth, backfill and cold-start limits, validated on construction. */
readonly class ReconciliationTuning {

	/** @var SourceSettings Settings read by the candidate sources */
	public SourceSettings $sources;

	/** @var int|null Maximum source depth override, or null for the configured default */
	public ?int $maxCandidateDepth;

	/** @var int|null Maximum IDs per eligibility call override, or null for the configured default */
	public ?int $maxEligibilityBatchSize;

	/** @var int Minimum ratings before personal sources are used; below it only the top-rated source runs */
	public int $minHistory;

	/**
	 * Store the overrides after validating each one.
	 * @param SourceSettings|null $sources Source settings, defaults when null
	 * @param int|null $maxCandidateDepth Maximum source depth override, at least 50, or null for the configured default
	 * @param int|null $maxEligibilityBatchSize Maximum IDs per eligibility call override, at least 1, or null for the configured default
	 * @param int $minHistory Non-negative ratings needed before personal sources run, at least 1
	 * @throws \InvalidArgumentException When a value is outside its allowed range
	 */
	public function __construct(
		?SourceSettings $sources = null,
		?int $maxCandidateDepth = null,
		?int $maxEligibilityBatchSize = null,
		int $minHistory = 1
	) {
		if ($maxCandidateDepth !== null) {
			Identifier::assertAtLeast($maxCandidateDepth, 50, 'Maximum candidate depth');
		}

		if ($maxEligibilityBatchSize !== null) {
			Identifier::assertAtLeast($maxEligibilityBatchSize, 1, 'Maximum eligibility batch size');
		}

		Identifier::assertAtLeast($minHistory, 1, 'Minimum history');

		$this->sources = $sources ?? new SourceSettings();
		$this->maxCandidateDepth = $maxCandidateDepth;
		$this->maxEligibilityBatchSize = $maxEligibilityBatchSize;
		$this->minHistory = $minHistory;
	}
}
