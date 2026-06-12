<?php
	
	namespace Quellabs\Canvas\Loom;
	
	/**
	 * Typed accessors for raw node arrays.
	 *
	 * The node tree is represented as array<string, mixed> throughout the engine.
	 * These helpers centralise the is_array() narrowing that would otherwise
	 * require inline @var annotations at every call site.
	 */
	final class NodeUtil {
		
		/**
		 * Extract the properties map from a node, returning an empty array when absent or
		 * when the value is not an array.
		 * @param array<string, mixed> $node
		 * @return array<string, mixed>
		 */
		public static function properties(array $node): array {
			/** @var array<string, mixed> $properties */
			$properties = is_array($node['properties'] ?? null) ? $node['properties'] : [];
			return $properties;
		}
		
		/**
		 * Extract the children list from a node, returning an empty array when absent or
		 * when the value is not an array.
		 * @param array<string, mixed> $node
		 * @return array<int, array<string, mixed>>
		 */
		public static function children(array $node): array {
			/** @var array<int, array<string, mixed>> $children */
			$children = is_array($node['children'] ?? null) ? $node['children'] : [];
			return $children;
		}
	}