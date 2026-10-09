<?php
	
	namespace Quellabs\Recommender\Internal;
	
	/** Ratings implied by purchases and clicks, shared by members and visitors. */
	final class ImplicitRating {
		
		/** @var float Rating recorded for a purchase */
		public const float PURCHASE = 1.0;
		
		/** @var float Rating recorded for a first click */
		public const float FIRST_CLICK = 0.7;
		
		/** @var float Amount each further click raises a rating */
		public const float CLICK_STEP = 0.01;
		
		/**
		 * Return the rating a click produces from the previous genuine rating.
		 * @param float|null $previous Previous genuine rating, or null when there is none
		 * @return float Rating after the click, capped at PURCHASE
		 */
		public static function afterClick(?float $previous): float {
			return $previous === null ? self::FIRST_CLICK : min(self::PURCHASE, $previous + self::CLICK_STEP);
		}
	}
