<?php
	
	namespace Quellabs\Recommender\Internal\Model;
	
	use Quellabs\Recommender\Reconciliation\CandidateScorer;
	use Quellabs\Recommender\Reconciliation\ScoredCandidate;
	use Quellabs\Recommender\Reconciliation\SourceEvidence;
	
	/** Scores a candidate as the click probability of a calibrated model at position one. */
	readonly class ClickModelScorer implements CandidateScorer {
		
		/** @var ClickModel Calibrated click model */
		private ClickModel $model;
		
		/**
		 * Store the model used for scoring.
		 * @param ClickModel $model Calibrated click model
		 */
		public function __construct(ClickModel $model) {
			$this->model = $model;
		}
		
		/**
		 * Return the model's click probability and per-source log-odds terms for the features.
		 * @param array<string, float> $features Serving-time feature values
		 * @param array<int, SourceEvidence> $evidence Unused; the model reads the features only
		 * @return ScoredCandidate Click probability with source contributions
		 */
		public function score(array $features, array $evidence): ScoredCandidate {
			return new ScoredCandidate($this->model->probability($features, 1), $this->model->sourceContributions($features));
		}
	}
