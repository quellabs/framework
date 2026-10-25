<?php

	namespace Quellabs\Payments\Contracts;

	enum SequenceType: string {
		case OneOff = 'oneoff';
		case First = 'first';
		case Recurring = 'recurring';
	}
