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
	 * @var Array<AFE_Element|AFE_Marker>
	 */
	private $stack = array();

	/**
	 * Returns the node at the given 1-offset index in the list of active formatting elements.
	 *
	 * Do not use this method; it is meant to be used only by the HTML Processor.
	 *
	 * @since 6.7.0
	 *
	 * @access private
	 *
	 * @param int $index Number of nodes from the top node to return.
	 * @return AFE_Element|AFE_Marker|null Node at the given index in the stack, if one exists, otherwise null.
	 */
	public function at( $nth ): AFE_Element|AFE_Marker|null {
		return $this->stack[ $nth - 1 ];
	}

	/**
	 * Reports if a specific node is in the stack of active formatting elements.
	 *
	 * @since 6.4.0
	 *
	 * @param WP_HTML_Token $token Look for this node in the stack.
	 * @return bool Whether the referenced node is in the stack of active formatting elements.
	 */
	public function contains_node( WP_HTML_Token $token ): bool {
		foreach ( $this->walk_up() as $item ) {
			if ( $item instanceof AFE_Element && $token->bookmark_name === $item->token->bookmark_name ) {
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
	 * @return int How many node are in the stack of active formatting elements.
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
	 * Inserts a marker at the end of the list of active formatting elements.
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
		$this->push( new AFE_Marker() );
	}

	/**
	 * Pushes a node onto the stack of active formatting elements.
	 *
	 * @since 6.4.0
	 *
	 * @see https://html.spec.whatwg.org/#push-onto-the-list-of-active-formatting-elements
	 *
	 * @param AFE_Element|AFE_Marker $token Push this node onto the stack.
	 */
	public function push( AFE_Element|AFE_Marker $afe ): void {
		if ( $afe instanceof AFE_Marker ) {
			$this->stack[] = $afe;
			return;
		}

		/*
		 * > If there are already three elements in the list of active formatting elements after the last marker,
		 * > if any, or anywhere in the list if there are no markers, that have the same tag name, namespace, and
		 * > attributes as element, then remove the earliest such element from the list of active formatting
		 * > elements. For these purposes, the attributes must be compared as they were when the elements were
		 * > created by the parser; two elements have the same attributes if all their parsed attributes can be
		 * > paired such that the two attributes in each pair have identical names, namespaces, and values
		 * > (the order of the attributes does not matter).
		 */
		$count = 0;
		foreach ( $this->walk_up_until_marker() as $item ) {
			if ( $item->is_equivalent( $afe ) ) {
				if ( ++$count >= 3 ) {
					return;
				}
			}
		}
		$this->stack[] = $afe;
	}

	/**
	 * Removes a node from the stack of active formatting elements.
	 *
	 * @since 6.4.0
	 *
	 * @param AFE_Element|AFE_Marker $node Remove this node from the stack, if it's there already.
	 * @return bool Whether the node was found and removed from the stack of active formatting elements.
	 */
	public function remove_node( AFE_Element $node ) {
		foreach ( $this->walk_up() as $position_from_end => $item ) {
			if ( $item instanceof AFE_Element && $node->token->bookmark_name !== $item->token->bookmark_name ) {
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
	 * Steps through the stack starting from the last added and stopping at the first marker, if present.
	 *
	 * This generator function is designed to be used inside a "foreach" loop.
	 *
	 * Example:
	 *
	 *     $html = '<em><table><td><i>We are here';
	 *     foreach ( $stack->walk_up_until_marker() as $node ) {
	 *         echo "{$node->node_name} -> ";
	 *     }
	 *     > I
	 *
	 * @since 6.7.0
	 */
	public function walk_up_until_marker() {
		foreach ( $this->walk_up() as $item ) {
			if ( $item instanceof AFE_Marker ) {
				break;
			}

			yield $item;
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
			if ( $item instanceof AFE_Marker ) {
				break;
			}
		}
	}
}

class AFE_Marker {}
class AFE_Element {
	/** @var string */
	public $namespace;
	/** @var string */
	public $tag_name;
	/** @var array<string, string|bool|null> */
	public $attributes;
	/** @var WP_HTML_Token */
	public $token;

	public function is_equivalent( self $afe ): bool {
		if (
			$this->namespace !== $afe->namespace ||
			$this->tag_name !== $afe->tag_name ||
			count( $this->attributes ) !== count( $afe->attributes )
		) {
			return false;
		}

		foreach ( $this->attributes as $name => $value ) {
			if ( ! array_key_exists( $name, $afe->attributes ) || $value !== $afe->attributes[ $name ] ) {
				return false;
			}
		}
		return true;
	}

	public function __construct( string $tag_namespace, string $tag_name, array $attributes, WP_HTML_Token $token ) {
		$this->namespace  = $tag_namespace;
		$this->tag_name   = $tag_name;
		$this->attributes = $attributes;
		$this->token      = $token;
	}
}
