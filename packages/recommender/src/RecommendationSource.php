<?php
	
	namespace Quellabs\Recommender;

	
	
	/** Candidate generators available to reconciliation. */
	enum RecommendationSource: string {
		
		case ItemLinks = 'item_links';
		case SlopeOne = 'slope_one';
		case UserSimilarity = 'user_similarity';
		case TopRated = 'top_rated';
		case NewProducts = 'new_products';
		
		/**
		 * Combine sources into a canonical source mask.
		 * @param array<int, RecommendationSource> $sources Sources to combine
		 * @return int Bit mask of the sources
		 */
		public static function mask(array $sources): int {
			return array_reduce($sources, fn(int $mask, self $source) => $mask | $source->bit(), 0);
		}
	
		/**
		 * Return the bit this source occupies in a canonical source mask.
		 * @return int Source bit
		 */
		public function bit(): int {
			return match ($this) {
				self::ItemLinks => 1,
				self::SlopeOne => 2,
				self::UserSimilarity => 4,
				self::TopRated => 8,
				self::NewProducts => 16,
			};
		}
	}
