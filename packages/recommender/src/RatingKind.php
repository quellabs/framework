<?php

	namespace Quellabs\Recommender;

	/** Which ratings a ratings query matches. */
	enum RatingKind: string {

		/** Genuine ratings from 0.0 to 1.0 */
		case Genuine = 'genuine';

		/** Not-interested ratings only */
		case NotInterested = 'not_interested';

		/** Genuine and not-interested ratings */
		case All = 'all';
	}
