<?php

	namespace Quellabs\Recommender;

	/** Sort order for a ratings listing. */
	enum RatingOrder: string {

		/** Oldest rating first */
		case DateAscending = 'date_ascending';

		/** Newest rating first */
		case DateDescending = 'date_descending';

		/** Lowest rating first */
		case RatingAscending = 'rating_ascending';

		/** Highest rating first */
		case RatingDescending = 'rating_descending';
	}
