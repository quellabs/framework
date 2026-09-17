<?php

	namespace Quellabs\ObjectQuel\Tests\Fixtures\Entities;

	/**
	 * Backing enum for MigRegOrderEntity::$priority — see
	 * QuelMigrationBuilderRegressionTest.
	 */
	enum MigRegPriority: string {
		case Low = 'low';
		case High = 'high';
	}
