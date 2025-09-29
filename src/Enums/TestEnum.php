<?php
	
	namespace App\Enums;
	
	enum TestEnum: string {
		case PENDING = 'pending';
		case SHIPPED = 'shipped';
		case DELIVERED = 'delivered';
	}
