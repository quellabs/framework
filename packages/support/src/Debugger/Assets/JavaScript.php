<?php
	
	namespace Quellabs\Support\Debugger\Assets;
	
	/**
	 * JavaScript functionality for HTML debug output
	 */
	class JavaScript {
		
		/**
		 * Get the JavaScript code for interactive functionality
		 * @return string JavaScript code
		 */
		public static function get(): string {
			return '
				<script>
		            function toggleCanvasDump(id) {
		                const element = document.getElementById("canvas-dump-" + id);
		                
		                if (!element) {
		                    return;
		                }
		                
		                const toggle = element.querySelector(".canvas-dump-toggle");
		                
		                if (!toggle) {
		                    return;
		                }
		                
		                if (element.classList.contains("canvas-dump-collapsed")) {
		                    element.classList.remove("canvas-dump-collapsed");
		                    toggle.textContent = "−";
		                } else {
		                    element.classList.add("canvas-dump-collapsed");
		                    toggle.textContent = "+";
		                }
		            }
		        </script>
	        ';
		}
	}