<?php

	namespace Quellabs\Recommender\Reconciliation;

	use Quellabs\Recommender\RecommendationSource;

	/** Candidate generators available to an anonymous visitor. User similarity needs a persisted member, so it is absent. */
	enum VisitorSource: string {

		case ItemLinks = 'item_links';
		case SlopeOne = 'slope_one';
		case TopRated = 'top_rated';
		case NewProducts = 'new_products';

		/**
		 * Return the shared source this visitor source corresponds to.
		 * @return RecommendationSource The matching candidate source
		 */
		public function recommendationSource(): RecommendationSource {
			return match ($this) {
				self::ItemLinks => RecommendationSource::ItemLinks,
				self::SlopeOne => RecommendationSource::SlopeOne,
				self::TopRated => RecommendationSource::TopRated,
				self::NewProducts => RecommendationSource::NewProducts,
			};
		}
	}
