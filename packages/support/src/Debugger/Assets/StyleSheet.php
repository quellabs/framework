<?php
	
	namespace Quellabs\Support\Debugger\Assets;
	
	/**
	 * CSS stylesheet for HTML debug output - Light Theme
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
			            background: linear-gradient(135deg, #ffffff 0%, #f8f9fa 100%);
			            border: 1px solid #dee2e6;
			            border-radius: 12px;
			            padding: 20px;
			            margin: 20px 0;
			            font-family: "JetBrains Mono", "Fira Code", "SF Mono", Consolas, "Liberation Mono", Menlo, monospace;
			            font-size: 14px;
			            line-height: 1.2;
			            color: #212529;
			            overflow-x: auto;
			            box-shadow: 0 4px 24px rgba(0, 0, 0, 0.08), 0 0 0 1px rgba(0, 0, 0, 0.04);
			            position: relative;
			            z-index: 9999;
			            backdrop-filter: blur(10px);
			        }
			        .canvas-dump-type {
			            font-weight: 500;
			            opacity: 0.7;
			            font-size: 12px;
			            background: rgba(108, 117, 125, 0.1);
			            color: #6c757d;
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
			            background: rgba(0, 123, 255, 0.1);
			            transform: translateY(-1px);
			        }
			        .canvas-dump-toggle {
			            display: inline-block;
			            width: 16px;
			            height: 16px;
			            line-height: 14px;
			            text-align: center;
			            background: linear-gradient(135deg, #007bff 0%, #0056b3 100%);
			            color: white;
			            border-radius: 50%;
			            font-size: 11px;
			            font-weight: bold;
			            margin-right: 8px;
			            cursor: pointer;
			            transition: all 0.2s ease;
			            box-shadow: 0 2px 8px rgba(0, 123, 255, 0.25);
			        }
			        .canvas-dump-toggle:hover {
			            transform: scale(1.1);
			            box-shadow: 0 4px 12px rgba(0, 123, 255, 0.4);
			        }
			        .canvas-dump-content {
			            margin-left: 16px;
			            border-left: 2px solid rgba(0, 123, 255, 0.2);
			            padding-left: 12px;
			            margin-top: 0;
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
			        .canvas-dump-length {
			            color: #6c757d;
			            font-style: italic;
			            font-size: 12px;
			            background: rgba(108, 117, 125, 0.1);
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
			        
			        /* Light theme syntax highlighting */
			        .canvas-dump [style*="#d14"] { /* strings */
			            color: #28a745 !important;
			            font-weight: 500;
			        }
			        .canvas-dump [style*="#005cc5"] { /* numbers/methods */
			            color: #007bff !important;
			            font-weight: 500;
			        }
			        .canvas-dump [style*="#6f42c1"] { /* booleans/properties */
			            color: #6f42c1 !important;
			            font-weight: 500;
			        }
			        .canvas-dump [style*="#6a737d"] { /* null */
			            color: #6c757d !important;
			            font-style: italic;
			        }
			        .canvas-dump [style*="#e36209"] { /* arrays */
			            color: #fd7e14 !important;
			            font-weight: 600;
			        }
			        .canvas-dump [style*="#28a745"] { /* objects */
			            color: #20c997 !important;
			            font-weight: 600;
			        }
			        .canvas-dump [style*="#fd7e14"] { /* resources */
			            color: #e83e8c !important;
			        }
			        .canvas-dump [style*="#032f62"] { /* keys */
			            color: #495057 !important;
			            font-weight: 600;
			        }
			    </style>
		    ';
		}
	}