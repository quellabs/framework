<?php
	
	if (!function_exists('d')) {
		/**
		 * Canvas dump - dump variables without dying
		 * @param mixed ...$vars
		 * @return void
		 */
		function d(...$vars): void {
			\Quellabs\Support\CanvasDebugger::dump(...$vars);
		}
	}
	
	if (!function_exists('dd')) {
		/**
		 * Canvas dump - dump variables and die
		 * @param mixed ...$vars
		 * @return void
		 */
		function dd(...$vars): void {
			\Quellabs\Support\CanvasDebugger::dumpAndDie(...$vars);
		}
	}