<?php

	namespace Quellabs\Payments\Contracts;

	enum MandateStatus: string {
		case Pending = 'PENDING';
		case Valid = 'VALID';
		case Invalid = 'INVALID';
	}
