<?php

namespace Quellabs\Recommender;

/** A ranked candidate with its bounded source evidence. */
readonly class ReconciledRecommendation {
    /** @param int $itemId Catalog ID
     * @param float|null $rankingScore Rank-fusion score or reference-position probability
     * @param array<int, SourceEvidence> $evidence Available source signals
     * @param array<string, float> $featureSnapshot Serving-time feature values
     * @param array<string, float> $sourceLogOddsContributions Fitted terms for all enabled sources
     * @param array<string, int> $searchedDepths Requested LIMIT reached per enabled source
     */
    public function __construct(
        public int $itemId,
        public ?float $rankingScore,
        public array $evidence,
        public array $featureSnapshot = [],
        public array $sourceLogOddsContributions = [],
        public array $searchedDepths = [],
    ) {
        if ($itemId < 0 || $itemId > 4294967295 || ($rankingScore !== null && !is_finite($rankingScore))) {
            throw new \InvalidArgumentException('Invalid reconciled recommendation.');
        }
        $sources = [];
        foreach ($evidence as $signal) {
            if (!$signal instanceof SourceEvidence || isset($sources[$signal->source->value])) {
                throw new \InvalidArgumentException('Evidence must contain distinct source signals.');
            }
            $sources[$signal->source->value] = true;
        }
        foreach ($featureSnapshot as $value) {
            if (!is_float($value) && !is_int($value) || !is_finite((float)$value)) {
                throw new \InvalidArgumentException('Feature values must be finite numbers.');
            }
        }
        foreach ($sourceLogOddsContributions as $name => $value) {
            if (!is_string($name) || !is_float($value) || !is_finite($value)) {
                throw new \InvalidArgumentException('Source contributions must be finite numbers.');
            }
        }
        foreach ($searchedDepths as $name => $depth) {
            if (!is_string($name) || !is_int($depth) || $depth < 1) {
                throw new \InvalidArgumentException('Searched depths must be positive integers.');
            }
        }
    }
}
