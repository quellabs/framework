<?php
	
	namespace Quellabs\Recommender;
	
	/** Kind of entity a recommendation is for. */
	enum SubjectKind: string {
		case Member = 'member';
		case Visitor = 'visitor';
		case Product = 'product';
	}
