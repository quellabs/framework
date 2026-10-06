<?php
	
	namespace Quellabs\Recommender;
	
	/** How the scores of a recommendation list should be interpreted. */
	enum ScoreKind: string {
		
		/** Displayed order only, with no ranking score */
		case Direct = 'direct';
		
		/** Items carry ranking scores from a scorer */
		case Ranked = 'ranked';
	}
