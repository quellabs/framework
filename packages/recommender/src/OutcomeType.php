<?php
	
	namespace Quellabs\Recommender;
	
	/** Observed action on a displayed item. */
	enum OutcomeType: string {
		case Click = 'click';
		case Purchase = 'purchase';
	}
