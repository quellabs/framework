<?php
	
	namespace Quellabs\Payments\Contracts;
	
	enum PaymentStatus: string {
		case Pending = 'PENDING';
		case Canceled = 'CANCEL';
		case Expired = 'EXPIRED';
		case Failed = 'FAILED';
		case Paid = 'PAID';
		case Refunded = 'REFUND';
		case Redirect = 'REDIRECT';
		case Unknown = 'UNKNOWN';
	}