<?php
	
	namespace Quellabs\Recommender\Evaluation;
	
	/** Observed action on a displayed item. */
	enum OutcomeType: string {
		
		case Click = 'click';
		case Purchase = 'purchase';
	}
