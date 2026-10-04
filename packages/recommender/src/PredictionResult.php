<?php

namespace Quellabs\Recommender;

/** A Slope One rating with summed directed-pair support. */
readonly class PredictionResult {
    /** @param int $itemId Product ID
     * @param float $predictedRating Clamped predicted rating
     * @param int $supportCount Sum of contributing pair counts
     */
    public function __construct(public int $itemId, public float $predictedRating, public int $supportCount) {
        if ($itemId < 0 || $itemId > 4294967295 || !is_finite($predictedRating)
            || $predictedRating < 0 || $predictedRating > 1 || $supportCount < 1) {
            throw new \InvalidArgumentException('Invalid prediction result.');
        }
    }
}
