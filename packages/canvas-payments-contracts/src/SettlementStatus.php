<?php

	namespace Quellabs\Payments\Contracts;

	enum SettlementStatus: string {
		case Open = 'OPEN';
		case Pending = 'PENDING';
		case Processing = 'PROCESSING';
		case PaidOut = 'PAIDOUT';
		case Failed = 'FAILED';
		case Unknown = 'UNKNOWN';
	}
