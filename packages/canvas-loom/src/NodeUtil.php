<?php
	
	namespace Quellabs\Canvas\Loom;
	
	/**
	 * Typed accessors for raw node arrays.
	 *
	 * The node tree is represented as array<string, mixed> throughout the engine.
	 * These helpers centralise the is_array() narrowing that would otherwise
	 * require inline @var annotations at every call site.
	 *
	 * @phpstan-type LoomNode array{type?: string, properties?: array<string, mixed>, children?: array<int, array<string, mixed>>, ...<string, mixed>}
	 * @phpstan-type LoomNodeList array<int, LoomNode>
	 */
	final class NodeUtil {
		
		/**
		 * Extract the properties map from a node, returning an empty array when absent or
		 * when the value is not an array.
		 * @phpstan-param LoomNode $node
		 * @return array<string, mixed>
		 */
		public static function properties(array $node): array {
			return is_array($node['properties'] ?? null) ? $node['properties'] : [];
		}
		
		/**
		 * Extract the children list from a node, returning an empty array when absent or
		 * when the value is not an array.
		 * @phpstan-param LoomNode $node
		 * @phpstan-return LoomNodeList
		 */
		public static function children(array $node): array {
			/** @var LoomNodeList $children */
			$children = is_array($node['children'] ?? null) ? $node['children'] : [];
			return $children;
		}
	}