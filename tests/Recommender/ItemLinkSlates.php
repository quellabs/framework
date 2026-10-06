<?php

namespace Quellabs\Recommender\Tests;

use Quellabs\Recommender\EligibilityProvider;
use Quellabs\Recommender\Reconciliation\ReconciledRecommendation;
use Quellabs\Recommender\Reconciliation\ReconciliationRequest;
use Quellabs\Recommender\Reconciliation\ReconciliationTuning;
use Quellabs\Recommender\Reconciliation\RecommendationReconciler;
use Quellabs\Recommender\Reconciliation\VisitorReconciliationRequest;
use Quellabs\Recommender\Reconciliation\VisitorSource;
use Quellabs\Recommender\RecommendationSource;
use Quellabs\Recommender\VisitorContext;

/** Item-links-only slates through the combiner, for tests of the item-links path. Requires IntegrationTestCase. */
trait ItemLinkSlates {

	/**
	 * Member slate from the item-links source alone.
	 * @param int $member Member ID
	 * @param EligibilityProvider|null $eligibility Provider, or null to accept every candidate
	 * @param int $limit Slate size
	 * @param ReconciliationTuning|null $tuning Threshold overrides, defaults when null
	 * @return array<int, ReconciledRecommendation> Displayed items in rank order
	 */
	protected function memberLinks(int $member, ?EligibilityProvider $eligibility = null, int $limit = 10, ?ReconciliationTuning $tuning = null): array {
		$request = new ReconciliationRequest($eligibility ?? $this->acceptAll(), [RecommendationSource::ItemLinks], $limit, 'test', tuning: $tuning);
		return (new RecommendationReconciler($this->connection, $this->config))->memberSlate($member, $request)->items;
	}

	/**
	 * Visitor slate from the item-links source alone.
	 * @param VisitorContext $visitor Visitor ratings
	 * @param EligibilityProvider|null $eligibility Provider, or null to accept every candidate
	 * @param int $limit Slate size
	 * @param ReconciliationTuning|null $tuning Threshold overrides, defaults when null
	 * @return array<int, ReconciledRecommendation> Displayed items in rank order
	 */
	protected function visitorLinks(VisitorContext $visitor, ?EligibilityProvider $eligibility = null, int $limit = 10, ?ReconciliationTuning $tuning = null): array {
		$request = new VisitorReconciliationRequest($eligibility ?? $this->acceptAll(), [VisitorSource::ItemLinks], $limit, 'test', tuning: $tuning);
		return (new RecommendationReconciler($this->connection, $this->config))->visitorSlate($visitor, $request)->items;
	}

	/**
	 * Provider that accepts every candidate.
	 * @return EligibilityProvider
	 */
	protected function acceptAll(): EligibilityProvider {
		return new class implements EligibilityProvider {
			/** @inheritDoc */
			public function filterEligible(array $candidateIds): array { return $candidateIds; }
		};
	}
}
