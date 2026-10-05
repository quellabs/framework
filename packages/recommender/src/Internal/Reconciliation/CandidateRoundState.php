<?php

namespace Quellabs\Recommender\Internal\Reconciliation;

use Quellabs\Recommender\RecommendationSource;

/**
 * Mutable bookkeeping for the candidate rounds of one reconciliation.
 *
 * Tracks each source's searched depth and nominations, which IDs were sent to
 * eligibility, and which were confirmed eligible.
 *
 * @phpstan-type CandidateRow array{id: int, score: float|null, count: int|null, contributors: array<int, int>}
 */
class CandidateRoundState {
	
	/** @var array<string, int> Searched depth per source value */
	private array $depths = [];
	
	/** @var int Maximum depth any source may grow to */
	private int $depthCap;
	
	/** @var array<string, array<int, CandidateRow>> Latest nominations per source value */
	private array $nominations = [];
	
	/** @var array<int, true> IDs already sent to eligibility */
	private array $submitted = [];
	
	/** @var array<int, true> IDs confirmed eligible */
	private array $eligible = [];
	
	/** @var int Count of eligible responses, including repeats across batches */
	private int $eligibleCount = 0;
	
	/**
	 * Start every source at the same initial depth.
	 * @param array<int, RecommendationSource> $sources Enabled sources
	 * @param int $initialDepth Starting depth for every source, already capped
	 * @param int $depthCap Maximum depth any source may grow to
	 */
	public function __construct(array $sources, int $initialDepth, int $depthCap) {
		foreach ($sources as $source) {
			$this->depths[$source->value] = $initialDepth;
			$this->nominations[$source->value] = [];
		}
		
		$this->depthCap = $depthCap;
	}
	
	/**
	 * Return the searched depth of a source.
	 * @param string $source Source value
	 * @return int Current depth
	 */
	public function depth(string $source): int {
		return $this->depths[$source];
	}
	
	/**
	 * Return every source's searched depth.
	 * @return array<string, int> Depth per source value
	 */
	public function depths(): array {
		return $this->depths;
	}
	
	/**
	 * Return the latest nominations of a source.
	 * @param string $source Source value
	 * @return array<int, CandidateRow> Nominations in source order
	 */
	public function nominations(string $source): array {
		return $this->nominations[$source];
	}
	
	/**
	 * Return how many candidates a source has nominated so far.
	 * @param string $source Source value
	 * @return int Nomination count
	 */
	public function nominationCount(string $source): int {
		return count($this->nominations[$source]);
	}
	
	/**
	 * Replace the nominations of a source with the results of the latest query.
	 * @param string $source Source value
	 * @param array<int, CandidateRow> $nominations Nominations in source order
	 * @return void
	 */
	public function setNominations(string $source, array $nominations): void {
		$this->nominations[$source] = $nominations;
	}
	
	/**
	 * Check whether an ID has already been sent to eligibility.
	 * @param int $id Candidate ID
	 * @return bool Whether the ID was submitted
	 */
	public function isSubmitted(int $id): bool {
		return isset($this->submitted[$id]);
	}
	
	/**
	 * Mark the IDs not yet submitted as submitted and return them in input order.
	 * @param array<int, int> $ids Candidate IDs, possibly repeated
	 * @return array<int, int> First occurrences that were not submitted before
	 */
	public function claimUnsubmitted(array $ids): array {
		$claimed = [];
		
		foreach ($ids as $id) {
			if (!isset($this->submitted[$id])) {
				$this->submitted[$id] = true;
				$claimed[] = $id;
			}
		}
		
		return $claimed;
	}
	
	/**
	 * Record an eligibility response for one candidate.
	 * @param int $id Eligible candidate ID
	 * @return void
	 */
	public function markEligible(int $id): void {
		$this->eligible[$id] = true;
		$this->eligibleCount++;
	}
	
	/**
	 * Return how many eligible responses have been recorded.
	 * @return int Eligible response count
	 */
	public function eligibleCount(): int {
		return $this->eligibleCount;
	}
	
	/**
	 * Check whether a candidate has been confirmed eligible.
	 * @param int $id Candidate ID
	 * @return bool Whether the candidate is eligible
	 */
	public function isEligible(int $id): bool {
		return isset($this->eligible[$id]);
	}
	
	/**
	 * Return the confirmed eligible IDs in the order they were confirmed.
	 * @return array<int, int> Eligible candidate IDs
	 */
	public function eligibleIds(): array {
		return array_keys($this->eligible);
	}
	
	/**
	 * Double the depth of every source that filled its current depth, up to the cap.
	 * @return bool Whether any source grew
	 */
	public function growDepths(): bool {
		$growing = false;
		
		foreach ($this->depths as $source => $depth) {
			if ($depth < $this->depthCap && count($this->nominations[$source]) >= $depth) {
				$this->depths[$source] = min($this->depthCap, 2 * $depth);
				$growing = true;
			}
		}
		
		return $growing;
	}
}
