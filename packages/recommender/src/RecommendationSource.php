<?php
	
	namespace Quellabs\Recommender;
	
	/** Candidate generators available to reconciliation. */
	enum RecommendationSource: string {
		case ItemLinks = 'item_links';
		case SlopeOne = 'slope_one';
		case UserSimilarity = 'user_similarity';
		case TopRated = 'top_rated';
		case NewProducts = 'new_products';
		
		/** @return int Source bit in a canonical set mask. */
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
