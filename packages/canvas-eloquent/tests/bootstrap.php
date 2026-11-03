<?php

	$localAutoload = __DIR__ . '/../vendor/autoload.php';
	$monorepoAutoload = __DIR__ . '/../../../vendor/autoload.php';

	if (is_file($localAutoload)) {
		require $localAutoload;
	} else {
		require $monorepoAutoload;
	}
