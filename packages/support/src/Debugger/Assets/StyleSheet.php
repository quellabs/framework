<?php
	
	namespace Quellabs\Support\Debugger\Assets;
	
	/**
	 * CSS stylesheet for HTML debug output - Light Theme (Optimized)
	 */
	class StyleSheet {
		
		/**
		 * Get the complete CSS stylesheet
		 * @return string CSS stylesheet
		 */
		public static function get(): string {
			return '
				<style>
					:root {
						--canvas-primary: #007bff;
						--canvas-primary-dark: #0056b3;
						--canvas-bg-light: #f8f9fa;
						--canvas-border: #dee2e6;
						--canvas-text: #212529;
						--canvas-text-muted: #6c757d;
						--canvas-text-secondary: #495057;
						--canvas-font-mono: "JetBrains Mono", "Fira Code", "SF Mono", Consolas, "Liberation Mono", Menlo, monospace;
						--canvas-shadow: 0 4px 24px rgba(0, 0, 0, 0.08), 0 0 0 1px rgba(0, 0, 0, 0.04);
						--canvas-shadow-hover: 0 4px 12px rgba(0, 123, 255, 0.4);
						--canvas-transition: all 0.2s ease;
					}
					
					.canvas-dump {
						background: linear-gradient(135deg, #fff 0%, var(--canvas-bg-light) 100%);
						border: 1px solid var(--canvas-border);
						border-radius: 12px;
						padding: 20px;
						margin: 20px 0;
						font: 14px/1.2 var(--canvas-font-mono);
						color: var(--canvas-text);
						overflow-x: auto;
						box-shadow: var(--canvas-shadow);
						position: relative;
						z-index: 9999;
						backdrop-filter: blur(10px);
					}
					
					.canvas-dump-type,
					.canvas-dump-length {
						font-size: 12px;
						background: rgba(108, 117, 125, 0.1);
						color: var(--canvas-text-muted);
						padding: 2px 6px;
						border-radius: 4px;
						margin-left: 8px;
					}
					
					.canvas-dump-type {
						font-weight: 500;
						opacity: 0.7;
					}
					
					.canvas-dump-length {
						font-style: italic;
						padding: 1px 4px;
						border-radius: 3px;
						margin-left: 6px;
					}
					
					.canvas-dump-expandable {
						cursor: pointer;
						user-select: none;
						display: inline-block;
						padding: 2px 4px;
						border-radius: 6px;
						transition: var(--canvas-transition);
						margin: 0;
					}
					
					.canvas-dump-expandable:hover {
						background: rgba(0, 123, 255, 0.1);
						transform: translateY(-1px);
					}
					
					.canvas-dump-toggle {
						display: inline-block;
						width: 16px;
						height: 16px;
						line-height: 14px;
						text-align: center;
						background: linear-gradient(135deg, var(--canvas-primary) 0%, var(--canvas-primary-dark) 100%);
						color: white;
						border-radius: 50%;
						font: bold 11px/14px var(--canvas-font-mono);
						margin-right: 8px;
						cursor: pointer;
						transition: var(--canvas-transition);
						box-shadow: 0 2px 8px rgba(0, 123, 255, 0.25);
					}
					
					.canvas-dump-toggle:hover {
						transform: scale(1.1);
						box-shadow: var(--canvas-shadow-hover);
					}
					
					.canvas-dump-content {
						margin: 0 0 0 16px;
						border-left: 2px solid rgba(0, 123, 255, 0.2);
						padding-left: 12px;
					}
					
					.canvas-dump-collapsed .canvas-dump-content {
						display: none;
					}
					
					.canvas-dump-key {
						font-weight: 600;
						text-shadow: 0 0 10px rgba(0, 123, 255, 0.1);
					}
					
					.canvas-dump-private {
						opacity: 0.6;
						font-style: italic;
					}
					
					.canvas-dump-protected {
						opacity: 0.75;
					}
					
					.canvas-dump-item,
					.canvas-dump-line {
						margin: 0;
						padding: 0;
						line-height: 1.2;
					}
					
					.canvas-dump-line {
						display: block;
						line-height: 1.3;
					}
					
					.canvas-dump-location {
						background: rgba(0, 123, 255, 0.1);
						border: 1px solid rgba(0, 123, 255, 0.2);
						border-radius: 6px;
						padding: 8px 12px;
						margin-bottom: 16px;
						font: 12px/1 var(--canvas-font-mono);
						color: var(--canvas-text-secondary);
						display: flex;
						align-items: center;
						gap: 8px;
					}
					
					.canvas-dump-location-icon {
						font-size: 14px;
					}
					
					.canvas-dump-location-text {
						font-weight: 600;
						color: var(--canvas-primary);
					}
					
					.canvas-dump-location-path {
						margin-left: auto;
						opacity: 0.6;
						font-size: 11px;
						max-width: 300px;
						overflow: hidden;
						text-overflow: ellipsis;
						white-space: nowrap;
						transition: opacity 0.2s ease;
					}
					
					.canvas-dump-location:hover .canvas-dump-location-path {
						opacity: 1;
					}
					
					/* Syntax highlighting - consolidated with better specificity */
					.canvas-dump [style*="#d14"] { color: #28a745 !important; font-weight: 500; } /* strings */
					.canvas-dump [style*="#005cc5"] { color: var(--canvas-primary) !important; font-weight: 500; } /* numbers/methods */
					.canvas-dump [style*="#6f42c1"] { color: #6f42c1 !important; font-weight: 500; } /* booleans/properties */
					.canvas-dump [style*="#6a737d"] { color: var(--canvas-text-muted) !important; font-style: italic; } /* null */
					.canvas-dump [style*="#e36209"] { color: #fd7e14 !important; font-weight: 600; } /* arrays */
					.canvas-dump [style*="#28a745"] { color: #20c997 !important; font-weight: 600; } /* objects */
					.canvas-dump [style*="#fd7e14"] { color: #e83e8c !important; } /* resources */
					.canvas-dump [style*="#032f62"] { color: var(--canvas-text-secondary) !important; font-weight: 600; } /* keys */
				</style>
			';
		}
	}