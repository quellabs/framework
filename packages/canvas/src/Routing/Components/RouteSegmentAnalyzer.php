<?php
	
	namespace Quellabs\Canvas\Routing\Components;
	
	/**
	 * RouteSegmentAnalyzer
	 *
	 * Handles URL classification and segment analysis for route matching optimization.
	 * Analyzes route patterns to determine segment types, calculate route priorities,
	 * and extract variable information for efficient routing decisions.
	 *
	 * Core responsibilities:
	 * - Segment classification: Determines the type of each route segment
	 * - Priority calculation: Assigns specificity scores for optimal matching order
	 * - Route categorization: Classifies entire routes for indexing strategies
	 * - Variable detection: Identifies parameters, wildcards, and partial variables
	 * - Pattern analysis: Evaluates route complexity for performance optimization
	 * - Path parsing: Breaks down route strings into processable segments
	 *
	 * Segment type classification:
	 * - Static: Exact string matches requiring no processing
	 * - Variable: Single parameters with optional type constraints
	 * - Single wildcard: Matches one segment with flexible content
	 * - Multi-wildcard: Matches multiple segments for path capturing
	 * - Partial variable: Mixed static/dynamic content within single segments
	 * - Multi-wildcard variable: Named multi-segment capturing
	 *
	 * Priority calculation algorithm:
	 * - Base score: Starting priority value
	 * - Static bonus: Higher scores for exact-match segments
	 * - Variable penalty: Reduced scores for dynamic segments
	 * - Wildcard penalty: Significant reduction for catch-all patterns
	 * - Length bonus: Slight preference for longer, more specific routes
	 * - Full static bonus: Maximum priority for completely static routes
	 *
	 * Route classification for indexing:
	 * - Static routes: No variables or wildcards, fastest matching
	 * - Dynamic routes: Contains variables but predictable structure
	 * - Wildcard routes: Contains catch-all patterns, requires special handling
	 *
	 * Analysis features:
	 * - Variable name extraction from parameter syntax
	 * - Type constraint identification and processing
	 * - Wildcard pattern recognition (*, **, named variants)
	 * - Partial variable detection for complex segment patterns
	 * - Route complexity assessment for performance optimization
	 *
	 * The analyzer provides the intelligence needed for the routing system to make
	 * optimal decisions about indexing strategies, matching algorithms, and priority
	 * ordering to ensure the most specific routes are matched first while maintaining
	 * high performance across different route pattern complexities.
	 */
	class RouteSegmentAnalyzer {
		
		/**
		 * Determine the type of route segment
		 *
		 * This method analyzes a single route segment to classify it into one of several
		 * types based on its pattern. The classification determines how the segment will
		 * be processed during route matching and affects the route's priority score.
		 * The order of checks is important - more specific patterns are checked first.
		 *
		 * Segment types (in order of specificity):
		 * - multi_wildcard: ** (matches multiple segments)
		 * - single_wildcard: * (matches one segment)
		 * - multi_wildcard_var: {path:**} or {files:.*} (named multi-wildcard)
		 * - partial_variable: user-{id}-profile (mixed static/variable)
		 * - variable: {id}, {slug} (single variable segment)
		 * - static: user, profile (exact match required)
		 *
		 * @param string $segment The route segment to analyze
		 * @return string The segment type identifier
		 */
		public function getSegmentType(string $segment): string {
			// Define segment type patterns in order of precedence
			// Each type has a checker function that returns true if the segment matches
			$segmentTypes = [
				// Anonymous multi-wildcard: matches unlimited segments
				'multi_wildcard'     => fn($s) => $s === '**',
				
				// Anonymous single wildcard: matches exactly one segment
				'single_wildcard'    => fn($s) => $s === '*',
				
				// Partial variables: segments mixing static text with variables
				// e.g., "user-{id}-profile" or "file.{name}.{ext}"
				'partial_variable'   => fn($s) => $this->hasPartialVariable($s) && !$this->isVariable($s),
				
				// Named multi-wildcard variables: {path:**} or {files:.*}
				// These capture multiple segments into a named variable
				'multi_wildcard_var' => fn($s) => str_ends_with($s, ':**}') || str_ends_with($s, ':.*}'),
				
				// Regular variables: {id}, {slug}, {category:int}
				// Identified by starting with opening brace
				'variable'           => fn($s) => !empty($s) && $s[0] === '{',
				
				// Static segments: exact string matches (fallback case)
				'static'             => fn($s) => true // Always matches as fallback
			];
			
			// Check each type in order until a match is found
			// Order matters: more specific types must be checked before general ones
			foreach ($segmentTypes as $type => $checker) {
				if ($checker($segment)) {
					return $type;
				}
			}
			
			// Fallback to static (should never reach here due to the static checker)
			return 'static';
		}
		
		/**
		 * Calculate priority for route (higher = more priority)
		 * More specific routes get higher priority than generic/wildcard routes
		 *
		 * This method implements a sophisticated priority scoring system that ensures
		 * more specific routes are matched before generic ones. The algorithm considers
		 * static segments (exact matches), variable segments, wildcards, and route length
		 * to determine specificity. Higher scores indicate higher priority in route matching.
		 *
		 * Priority factors:
		 * - Static segments increase priority (more specific)
		 * - Variable segments decrease priority (less specific)
		 * - Wildcards heavily decrease priority (least specific)
		 * - Longer routes get slight priority boost
		 * - Fully static routes get significant bonus
		 *
		 * @param string $routePath The complete route path to analyze
		 * @return int Priority score (higher = more specific, matched first)
		 */
		public function calculateRoutePriority(string $routePath): int {
			// Start with a base priority score
			$priority = 1000;
			
			// Parse route into segments, filtering out empty segments
			// e.g., "/user/profile/" becomes ["user", "profile"]
			$segments = $this->parseRoutePath($routePath);
			
			// Count total segments and track static segments for bonuses
			$segmentCount = count($segments);
			$staticSegments = 0;
			$penalties = 0; // Accumulate penalty points for non-specific segments
			
			// Analyze each segment to determine its impact on priority
			foreach ($segments as $segment) {
				// Determine the segment type (static, variable, wildcard, etc.)
				$segmentType = $this->getSegmentType($segment);
				
				// Apply type-specific penalty based on how generic the segment is
				// Special handling for partial variables with multi-wildcards
			    if (
					$segmentType === 'partial_variable' &&
				    (
						str_contains($segment, ':**') ||
						str_contains($segment, ':.*')
				    )
			    ) {
				    $penalties += 200;  // Treat like multi_wildcard_var
			    } else {
				    $penalties += $this->getSegmentPenalty($segmentType);
			    }
	
				// Count static segments for bonus calculation
				if ($segmentType === 'static') {
					$staticSegments++;
				}
			}
			
			// Apply calculated penalties to reduce priority for generic segments
			$priority -= $penalties;
			
			// Bonus points for static segments (20 points each)
			// Static segments make routes more specific and should be prioritized
			$priority += $staticSegments * 20;
			
			// Small bonus for longer routes (5 points per segment)
			// Longer routes are generally more specific than shorter ones
			$priority += $segmentCount * 5;
			
			// Special bonus for completely static routes (no variables/wildcards)
			// These are the most specific possible and should always match first
			if ($penalties === 0) {
				$priority += 100;
			}
			
			return $priority;
		}
		
		/**
		 * Classify the route type for indexing optimization
		 * @param array $route Route configuration array with compiled_pattern
		 * @return string 'wildcard', 'dynamic', or 'static'
		 */
		public function classifyRoute(array $route): string {
			// Check each segment for classification
			foreach ($route['compiled_pattern'] as $segment) {
				// Wildcards take precedence (*, **, *var)
				if (in_array($segment['type'], ['multi_wildcard', 'single_wildcard', 'multi_wildcard_var'])) {
					return 'wildcard';
				}
				
				// Variables make route dynamic ({id}, {slug})
				if (in_array($segment['type'], ['variable', 'partial_variable'])) {
					return 'dynamic';
				}
			}
			
			// No variables or wildcards = static route
			return 'static';
		}
		
		/**
		 * Get penalty points for the segment type
		 *
		 * This method assigns penalty scores to different segment types based on
		 * their specificity. Higher penalties are given to more generic segment
		 * types, which lowers their priority in route matching. This ensures
		 * that specific routes are matched before generic wildcard routes.
		 *
		 * Penalty scale (higher = less specific = lower priority):
		 * - Multi-wildcards: 200 points (least specific)
		 * - Single wildcards: 100 points
		 * - Variables: 50 points
		 * - Partial variables: 30 points
		 * - Static segments: 0 points (most specific)
		 *
		 * @param string $segmentType The segment type to get a penalty for
		 * @return int Penalty points to subtract from route priority
		 */
		public function getSegmentPenalty(string $segmentType): int {
			return match ($segmentType) {
				// Multi-wildcards are least specific - highest penalty
				// They match any number of segments, making them very generic
				'multi_wildcard', 'multi_wildcard_var' => 200,
				
				// Single wildcards match any one segment - high penalty
				'single_wildcard' => 100,
				
				// Variables match one segment with optional constraints - moderate penalty
				'variable' => 50,
				
				// Partial variables have some static content - lower penalty
				'partial_variable' => 30,
				
				// Static segments are most specific - no penalty
				// They require exact matches and should have highest priority
				default => 0
			};
		}
		
		/**
		 * Checks if a route segment contains partial variables (like "v{id:int}")
		 * @param string $segment Route segment to check
		 * @return bool True if segment contains embedded variables
		 */
		public function hasPartialVariable(string $segment): bool {
			// Check if the segment contains any variable pattern (text within curly braces)
			$hasVariable = preg_match('/\{[^}]+}/', $segment);
			
			// Determine if the entire segment is a complete variable
			// A complete variable starts with '{' and ends with '}'
			$isCompleteVariable = !empty($segment) && $segment[0] === '{' && str_ends_with($segment, '}');
			
			// Return true only if segment has variables but is NOT a complete variable
			// This identifies partial variables like "user{id}" or "v{id:int}"
			// but excludes complete variables like "{id}" or "{name:string}"
			return $hasVariable && !$isCompleteVariable;
		}
		
		/**
		 * Determines if a route segment is a variable placeholder
		 * @param string $segment Route segment to check
		 * @return bool True if the segment is a variable placeholder
		 */
		public function isVariable(string $segment): bool {
			return !empty($segment) && $segment[0] === '{' && str_ends_with($segment, '}');
		}
		
		/**
		 * Extracts the variable name from a route segment
		 * @param string $segment Route segment containing variable (e.g., "{id}", "v{path:**}"
		 * @return string The extracted variable name (e.g., "id", "path", "id")
		 */
		public function extractVariableName(string $segment): string {
			// Handle simple variable segments that are entirely wrapped in braces
			// For "{id}" this removes the surrounding braces to get "id"
			$variableName = trim($segment, '{}');
			
			// Check if the variable has a type constraint (e.g., "id:int" or "path:**")
			// If not, return the variable name as-is
			if (!str_contains($variableName, ':')) {
				return $variableName;
			}
			
			// Split on the first colon to separate variable name from type constraint
			// For "path:**" this returns "path", ignoring the ":**" wildcard type
			return explode(':', $variableName, 2)[0];
		}
		
		/**
		 * Get the first segment of a route path
		 * @param string $routePath
		 * @return string
		 */
		public function getFirstSegment(string $routePath): string {
			$segments = $this->parseRoutePath($routePath);
			return $segments[0] ?? '';
		}
		
		/**
		 * Parses a route path string into clean segments for matching
		 * @param string $routePath Raw route path like '/users/{id}/posts'
		 * @return array Clean route segments like ['users', '{id}', 'posts']
		 */
		private function parseRoutePath(string $routePath): array {
			$segments = explode('/', ltrim($routePath, '/'));
			
			return array_filter($segments, function ($segment) {
				return $segment !== '';
			});
		}
	}