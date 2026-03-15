<?php
	
	namespace Quellabs\Contracts\Payment;
	
	enum PaymentStatus: string {
		case Initiated = 'INITIATED';
		case Pending = 'PENDING';
		case Canceled = 'CANCEL';
		case Expired = 'EXPIRED';
		case Failed = 'FAILED';
		case Paid = 'PAID';
		case RefundInitiated = 'REFUND_INITIATED';
		case Refunded = 'REFUND';
		case Unknown = 'UNKNOWN';
	}