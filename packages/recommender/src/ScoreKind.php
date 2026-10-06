<?php

	namespace Quellabs\Recommender;

	/** How the scores of a recommendation list should be interpreted. */
	enum ScoreKind: string {

		/** Scores come straight from the displayed order, with no ranking score */
		case Direct = 'direct';

		/** Scores are reciprocal-rank fusion values */
		case RankFusion = 'rank_fusion';

		/** Scores are click probabilities from an activated model */
		case ClickProbability = 'click_probability';
	}
