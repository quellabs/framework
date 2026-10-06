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
	 * Return ranked candidates for the subject, filtered through the eligibility provider.
	 * @param Subject $subject Subject the candidates are for
	 * @param EligibilityProvider|null $eligibility Restricts candidates, or null for all
	 * @param int $limit Maximum results, or zero for all
	 * @param SourceSettings $settings Source settings; sources read only the fields they use
	 * @param int|null $category Category override
	 * @return array<int, RecommendationResult>
	 * @throws \InvalidArgumentException When the source does not support the subject kind
	 */
	public function candidates(Subject $subject, ?EligibilityProvider $eligibility, int $limit,
		SourceSettings $settings, ?int $category = null): array;
}
