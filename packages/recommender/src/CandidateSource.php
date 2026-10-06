<?php

namespace Quellabs\Recommender;

use Quellabs\Recommender\Reconciliation\SourceSettings;

/** Candidate generator with one signature shared by every source. */
interface CandidateSource {

	/**
	 * Report whether this source answers for a subject kind.
	 * @param SubjectKind $kind Subject kind
	 * @return bool True when candidates() accepts subjects of this kind
	 */
	public function supports(SubjectKind $kind): bool;

	/**
	 * Return the top ranked candidates for the subject. With eligibility, deeper candidates are checked until the
	 * limit is met or the depth cap is reached, so fewer results may be returned.
	 * @param Subject $subject Subject the candidates are for
	 * @param EligibilityProvider|null $eligibility Restricts candidates, or null for all
	 * @param int $limit Maximum results, or zero for all
	 * @param SourceSettings $settings Source settings; sources read only the fields they use
	 * @param int|null $category Category override
	 * @return array<int, RecommendationResult>
	 * @throws \InvalidArgumentException When the source does not support the subject kind
	 */
	public function candidates(Subject $subject, ?EligibilityProvider $eligibility, int $limit, SourceSettings $settings, ?int $category = null): array;

	/**
	 * Score the given products for a subject, without depth or eligibility. Used to score candidates that other sources nominated.
	 * @param Subject $subject Subject the scores are for
	 * @param array<int, int> $productIds Product IDs to score
	 * @param SourceSettings $settings Source settings; sources read only the fields they use
	 * @param int|null $category Category override
	 * @return array<int, RecommendationResult> Scored products, in no particular order; unscored products are omitted
	 * @throws \InvalidArgumentException When the source does not support the subject kind
	 */
	public function scores(Subject $subject, array $productIds, SourceSettings $settings, ?int $category = null): array;
}
