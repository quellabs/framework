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
	 * Return the top ranked candidates for the subject that pass eligibility. Only one round is made, so fewer
	 * than $depth results may be returned. Callers that need more deepen the request.
	 * @param Subject $subject Subject the candidates are for
	 * @param EligibilityProvider|null $eligibility Restricts candidates, or null for all
	 * @param int $depth Number of top candidates to consider before eligibility, or zero for all
	 * @param SourceSettings $settings Source settings; sources read only the fields they use
	 * @param int|null $category Category override
	 * @return array<int, RecommendationResult>
	 * @throws \InvalidArgumentException When the source does not support the subject kind
	 */
	public function candidates(Subject $subject, ?EligibilityProvider $eligibility, int $depth,
		SourceSettings $settings, ?int $category = null): array;
}
