<?php
	
	namespace Quellabs\Support\Debugger\Assets;
	
	/**
	 * CSS stylesheet for HTML debug output
	 */
	class StyleSheet {
		
		/**
		 * Get the complete CSS stylesheet
		 * @return string CSS stylesheet
		 */
		public static function get(): string {
			return '
				<style>
			        .canvas-dump {
			            background: linear-gradient(135deg, #1e1e1e 0%, #2d2d2d 100%);
			            border: 1px solid #404040;
			            border-radius: 12px;
			            padding: 20px;
			            margin: 20px 0;
			            font-family: "JetBrains Mono", "Fira Code", "SF Mono", Consolas, "Liberation Mono", Menlo, monospace;
			            font-size: 14px;
			            line-height: 1.2;
			            color: #e9ecef;
			            overflow-x: auto;
			            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.3), 0 0 0 1px rgba(255, 255, 255, 0.05);
			            position: relative;
			            z-index: 9999;
			            backdrop-filter: blur(10px);
			        }
			        .canvas-dump-type {
			            font-weight: 500;
			            opacity: 0.8;
			            font-size: 12px;
			            background: rgba(108, 117, 125, 0.2);
			            padding: 2px 6px;
			            border-radius: 4px;
			            margin-left: 8px;
			        }
			        .canvas-dump-expandable {
			            cursor: pointer;
			            user-select: none;
			            display: inline-block;
			            padding: 2px 4px;
			            border-radius: 6px;
			            transition: all 0.2s ease;
			            margin: 0;
			        }
			        .canvas-dump-expandable:hover {
			            background: rgba(255, 255, 255, 0.1);
			            transform: translateY(-1px);
			        }
			        .canvas-dump-toggle {
			            display: inline-block;
			            width: 16px;
			            height: 16px;
			            line-height: 14px;
			            text-align: center;
			            background: linear-gradient(135deg, #007acc 0%, #0096ff 100%);
			            color: white;
			            border-radius: 50%;
			            font-size: 11px;
			            font-weight: bold;
			            margin-right: 8px;
			            cursor: pointer;
			            transition: all 0.2s ease;
			            box-shadow: 0 2px 8px rgba(0, 150, 255, 0.3);
			        }
			        .canvas-dump-toggle:hover {
			            transform: scale(1.1);
			            box-shadow: 0 4px 12px rgba(0, 150, 255, 0.5);
			        }
			        .canvas-dump-content {
			            margin-left: 16px;
			            border-left: 2px solid rgba(255, 255, 255, 0.1);
			            padding-left: 12px;
			            margin-top: 0;
			        }
			        .canvas-dump-collapsed .canvas-dump-content {
			            display: none;
			        }
			        .canvas-dump-key {
			            font-weight: 600;
			            text-shadow: 0 0 10px rgba(3, 47, 98, 0.5);
			        }
			        .canvas-dump-private {
			            opacity: 0.7;
			            font-style: italic;
			        }
			        .canvas-dump-protected {
			            opacity: 0.85;
			        }
			        .canvas-dump-length {
			            color: #adb5bd;
			            font-style: italic;
			            font-size: 12px;
			            background: rgba(173, 181, 189, 0.1);
			            padding: 1px 4px;
			            border-radius: 3px;
			            margin-left: 6px;
			        }
			        .canvas-dump-item {
			            margin: 0;
			            line-height: 1.2;
			        }
			        
			        .canvas-dump-line {
			            display: block;
			            margin: 0;
			            padding: 0;
			            line-height: 1.3;
			        }
			        
			        /* Enhanced syntax highlighting */
			        .canvas-dump [style*="#d14"] { /* strings */
			            color: #98d982 !important;
			            font-weight: 500;
			        }
			        .canvas-dump [style*="#005cc5"] { /* numbers/methods */
			            color: #79c0ff !important;
			            font-weight: 500;
			        }
			        .canvas-dump [style*="#6f42c1"] { /* booleans/properties */
			            color: #d2a8ff !important;
			            font-weight: 500;
			        }
			        .canvas-dump [style*="#6a737d"] { /* null */
			            color: #8b949e !important;
			            font-style: italic;
			        }
			        .canvas-dump [style*="#e36209"] { /* arrays */
			            color: #ffa657 !important;
			            font-weight: 600;
			        }
			        .canvas-dump [style*="#28a745"] { /* objects */
			            color: #7ee787 !important;
			            font-weight: 600;
			        }
			        .canvas-dump [style*="#fd7e14"] { /* resources */
			            color: #ffab70 !important;
			        }
			        .canvas-dump [style*="#032f62"] { /* keys */
			            color: #79c0ff !important;
			            font-weight: 600;
			        }
			    </style>
		    ';
		}
	}