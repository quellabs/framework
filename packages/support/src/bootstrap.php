<?php
	
	if (!function_exists('dump')) {
		/**
		 * Canvas dump - dump variables without dying
		 * @param mixed ...$vars
		 * @return void
		 */
		function dump(...$vars): void {
			\Quellabs\Support\CanvasDebugger::dump(...$vars);
		}
	}
	
	if (!function_exists('d')) {
		/**
		 * Canvas dump - dump variables and dying
		 * @param mixed ...$vars
		 * @return void
		 */
		function d(...$vars) {
			\Quellabs\Support\CanvasDebugger::dumpAndDie(...$vars);
		}
	}