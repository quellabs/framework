<?php
	
	namespace Quellabs\Recommender\Reconciliation;
	
	/** A scorer chosen for a partition, with the ID stored on the lists it ranks. */
	readonly class ActiveScorer {
		
		/** @var string Opaque scorer ID recorded on ranked lists */
		public string $id;
		
		/** @var CandidateScorer Scorer applied to each candidate */
		public CandidateScorer $scorer;
		
		/**
		 * Store the ID and scorer.
		 * @param string $id Opaque scorer ID
		 * @param CandidateScorer $scorer Scorer applied to each candidate
		 */
		public function __construct(string $id, CandidateScorer $scorer) {
			$this->id = $id;
			$this->scorer = $scorer;
		}
	}
