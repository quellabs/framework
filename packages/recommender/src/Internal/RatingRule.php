<?php
	
	namespace Quellabs\Recommender\Internal;
	
	/** Validity rule shared by every rating entry point. */
	final class RatingRule {
	
		/**
		 * Check that a rating is finite and either within [0.0, 1.0] or the not-interested sentinel.
		 * @param float $rating Rating to check
		 * @param float $notInterested The configured not-interested sentinel
		 * @return bool
		 */
		public static function isValid(float $rating, float $notInterested): bool {
			return is_finite($rating) && $rating <= 1.0 && ($rating >= 0.0 || $rating === $notInterested);
		}
	}
