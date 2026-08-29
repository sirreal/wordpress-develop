<?php
/**
 * HTML API: WP_HTML_Active_Formatting_Elements class
 *
 * @package WordPress
 * @subpackage HTML-API
 * @since 6.4.0
 */

/**
 * Core class used by the HTML processor during HTML parsing
 * for managing the stack of active formatting elements.
 *
 * This class is designed for internal use by the HTML processor.
 *
 * > Initially, the list of active formatting elements is empty.
 * > It is used to handle mis-nested formatting element tags.
 * >
 * > The list contains elements in the formatting category, and markers.
 * > The markers are inserted when entering applet, object, marquee,
 * > template, td, th, and caption elements, and are used to prevent
 * > formatting from "leaking" into applet, object, marquee, template,
 * > td, th, and caption elements.
 * >
 * > In addition, each element in the list of active formatting elements
 * > is associated with the token for which it was created, so that
 * > further elements can be created for that token if necessary.
 *
 * @since 6.4.0
 *
 * @access private
 * @ignore
 *
 * @see https://html.spec.whatwg.org/#list-of-active-formatting-elements
 * @see WP_HTML_Processor
 */
class WP_HTML_Active_Formatting_Elements {
	/**
	 * Holds the stack of active formatting element references.
	 *
	 * @since 6.4.0
	 *
	 * @var WP_HTML_Token[]
	 */
	private $stack = array();

	/**
	 * Reports if a specific node is in the stack of active formatting elements.
	 *
	 * @since 6.4.0
	 *
	 * @param WP_HTML_Token $token Look for this node in the stack.
	 * @return bool Whether the referenced node is in the stack of active formatting elements.
	 */
	public function contains_node( WP_HTML_Token $token ) {
		foreach ( $this->walk_up() as $item ) {
			if ( $token->bookmark_name === $item->bookmark_name ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Returns how many nodes are currently in the stack of active formatting elements.
	 *
	 * @since 6.4.0
	 *
	 * @return int How many nodes are in the stack of active formatting elements.
	 */
	public function count() {
		return count( $this->stack );
	}

	/**
	 * Returns the node at the end of the stack of active formatting elements,
	 * if one exists. If the stack is empty, returns null.
	 *
	 * @since 6.4.0
	 *
	 * @return WP_HTML_Token|null Last node in the stack of active formatting elements, if one exists, otherwise null.
	 */
	public function current_node() {
		$current_node = end( $this->stack );

		return $current_node ? $current_node : null;
	}

	/**
	 * Inserts a "marker" at the end of the list of active formatting elements.
	 *
	 * > The markers are inserted when entering applet, object, marquee,
	 * > template, td, th, and caption elements, and are used to prevent
	 * > formatting from "leaking" into applet, object, marquee, template,
	 * > td, th, and caption elements.
	 *
	 * @see https://html.spec.whatwg.org/#concept-parser-marker
	 *
	 * @since 6.7.0
	 */
	public function insert_marker(): void {
		$this->push( new WP_HTML_Token( null, 'marker', false ) );
	}

	/**
	 * Pushes a node onto the stack of active formatting elements.
	 *
	 * @since 6.4.0
	 *
	 * @see https://html.spec.whatwg.org/#push-onto-the-list-of-active-formatting-elements
	 *
	 * @param WP_HTML_Token $token Push this node onto the stack.
	 */
	public function push( WP_HTML_Token $token ) {
		/*
		 * Noah's Ark clause: Limit to 3 identical formatting elements.
		 *
		 * > If there are already three elements in the list of active formatting elements after the last marker,
		 * > if any, or anywhere in the list if there are no markers, that have the same tag name, namespace, and
		 * > attributes as element, then remove the earliest such element from the list of active formatting
		 * > elements. For these purposes, the attributes must be compared as they were when the elements were
		 * > created by the parser; two elements have the same attributes if all their parsed attributes can be
		 * > paired such that the two attributes in each pair have identical names, namespaces, and values
		 * > (the order of the attributes does not matter).
		 *
		 * @see https://html.spec.whatwg.org/#push-onto-the-list-of-active-formatting-elements
		 */
		$dominated_count      = 0;
		$earliest_match_index = null;

		// Walk backwards, counting matches until we hit a marker.
		for ( $i = count( $this->stack ) - 1; $i >= 0; $i-- ) {
			$entry = $this->stack[ $i ];

			// Markers stop the search.
			if ( 'marker' === $entry->node_name ) {
				break;
			}

			// Check if this entry matches the token being pushed.
			if ( self::elements_have_same_identity( $token, $entry ) ) {
				++$dominated_count;
				$earliest_match_index = $i;
			}
		}

		// If 3 identical elements exist, remove the earliest.
		if ( $dominated_count >= 3 && null !== $earliest_match_index ) {
			array_splice( $this->stack, $earliest_match_index, 1 );
		}

		// > Add element to the list of active formatting elements.
		$this->stack[] = $token;
	}

	/**
	 * Removes a node from the stack of active formatting elements.
	 *
	 * @since 6.4.0
	 *
	 * @param WP_HTML_Token $token Remove this node from the stack, if it's there already.
	 * @return bool Whether the node was found and removed from the stack of active formatting elements.
	 */
	public function remove_node( WP_HTML_Token $token ) {
		foreach ( $this->walk_up() as $position_from_end => $item ) {
			if ( $token->bookmark_name !== $item->bookmark_name ) {
				continue;
			}

			$position_from_start = $this->count() - $position_from_end - 1;
			array_splice( $this->stack, $position_from_start, 1 );
			return true;
		}

		return false;
	}

	/**
	 * Steps through the stack of active formatting elements, starting with the
	 * top element (added first) and walking downwards to the one added last.
	 *
	 * This generator function is designed to be used inside a "foreach" loop.
	 *
	 * Example:
	 *
	 *     $html = '<em><strong><a>We are here';
	 *     foreach ( $stack->walk_down() as $node ) {
	 *         echo "{$node->node_name} -> ";
	 *     }
	 *     > EM -> STRONG -> A ->
	 *
	 * To start with the most-recently added element and walk towards the top,
	 * see WP_HTML_Active_Formatting_Elements::walk_up().
	 *
	 * @since 6.4.0
	 */
	public function walk_down() {
		$count = count( $this->stack );

		for ( $i = 0; $i < $count; $i++ ) {
			yield $this->stack[ $i ];
		}
	}

	/**
	 * Steps through the stack of active formatting elements, starting with the
	 * bottom element (added last) and walking upwards to the one added first.
	 *
	 * This generator function is designed to be used inside a "foreach" loop.
	 *
	 * Example:
	 *
	 *     $html = '<em><strong><a>We are here';
	 *     foreach ( $stack->walk_up() as $node ) {
	 *         echo "{$node->node_name} -> ";
	 *     }
	 *     > A -> STRONG -> EM ->
	 *
	 * To start with the first added element and walk towards the bottom,
	 * see WP_HTML_Active_Formatting_Elements::walk_down().
	 *
	 * @since 6.4.0
	 */
	public function walk_up() {
		for ( $i = count( $this->stack ) - 1; $i >= 0; $i-- ) {
			yield $this->stack[ $i ];
		}
	}

	/**
	 * Clears the list of active formatting elements up to the last marker.
	 *
	 * > When the steps below require the UA to clear the list of active formatting elements up to
	 * > the last marker, the UA must perform the following steps:
	 * >
	 * > 1. Let entry be the last (most recently added) entry in the list of active
	 * >    formatting elements.
	 * > 2. Remove entry from the list of active formatting elements.
	 * > 3. If entry was a marker, then stop the algorithm at this point.
	 * >    The list has been cleared up to the last marker.
	 * > 4. Go to step 1.
	 *
	 * @see https://html.spec.whatwg.org/multipage/parsing.html#clear-the-list-of-active-formatting-elements-up-to-the-last-marker
	 *
	 * @since 6.7.0
	 */
	public function clear_up_to_last_marker(): void {
		foreach ( $this->walk_up() as $item ) {
			array_pop( $this->stack );
			if ( 'marker' === $item->node_name ) {
				break;
			}
		}
	}

	/**
	 * Gets the entry at a specific index in the list.
	 *
	 * @since 6.8.0
	 *
	 * @param int $index Zero-based index from the start of the list.
	 * @return WP_HTML_Token|null The token at that index, or null if out of bounds.
	 */
	public function get_at( int $index ): ?WP_HTML_Token {
		return $this->stack[ $index ] ?? null;
	}

	/**
	 * Replaces the entry at a specific index with a new token.
	 *
	 * @since 6.8.0
	 *
	 * @param int           $index Zero-based index from the start of the list.
	 * @param WP_HTML_Token $token The new token to place at that index.
	 * @return bool Whether the replacement was successful.
	 */
	public function replace_at( int $index, WP_HTML_Token $token ): bool {
		if ( $index < 0 || $index >= count( $this->stack ) ) {
			return false;
		}
		$this->stack[ $index ] = $token;
		return true;
	}

	/**
	 * Finds the index of a token in the list.
	 *
	 * @since 6.8.0
	 *
	 * @param WP_HTML_Token $token The token to find.
	 * @return int|null The index, or null if not found.
	 */
	public function index_of( WP_HTML_Token $token ): ?int {
		foreach ( $this->stack as $index => $item ) {
			if ( $token->bookmark_name === $item->bookmark_name ) {
				return $index;
			}
		}
		return null;
	}

	/**
	 * Determines if two tokens represent the same formatting element.
	 *
	 * Two elements are considered identical if they have the same:
	 * - Tag name
	 * - Namespace
	 * - Attributes (names, namespaces, and values)
	 *
	 * @since 6.8.0
	 *
	 * @param WP_HTML_Token $a First token.
	 * @param WP_HTML_Token $b Second token.
	 * @return bool Whether the tokens represent identical formatting elements.
	 */
	private static function elements_have_same_identity( WP_HTML_Token $a, WP_HTML_Token $b ): bool {
		// Tag name must match.
		if ( $a->node_name !== $b->node_name ) {
			return false;
		}

		// Namespace must match.
		if ( $a->namespace !== $b->namespace ) {
			return false;
		}

		// Attributes must match.
		return self::attributes_are_equal(
			$a->attributes ?? array(),
			$b->attributes ?? array()
		);
	}

	/**
	 * Determines if two attribute arrays are equal.
	 *
	 * Comparison is case-insensitive for names (keys are already lowercase),
	 * exact for values, and order-independent.
	 *
	 * @since 6.8.0
	 *
	 * @param array $a First attributes array.
	 * @param array $b Second attributes array.
	 * @return bool Whether the attributes are equal.
	 */
	private static function attributes_are_equal( array $a, array $b ): bool {
		// Different count means different attributes.
		if ( count( $a ) !== count( $b ) ) {
			return false;
		}

		// Empty arrays are equal.
		if ( 0 === count( $a ) ) {
			return true;
		}

		// Compare each attribute (keys already lowercase from capture).
		foreach ( $a as $name => $value ) {
			if ( ! array_key_exists( $name, $b ) ) {
				return false;
			}
			if ( $value !== $b[ $name ] ) {
				return false;
			}
		}

		return true;
	}
}
