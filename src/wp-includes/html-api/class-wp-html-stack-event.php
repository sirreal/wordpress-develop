<?php
/**
 * HTML API: WP_HTML_Stack_Event class
 *
 * @package WordPress
 * @subpackage HTML-API
 * @since 6.6.0
 */

/**
 * Core class used by the HTML Processor as a record for stack operations.
 *
 * This class is for internal usage of the WP_HTML_Processor class.
 *
 * @access private
 * @ignore
 *
 * @since 6.6.0
 *
 * @see WP_HTML_Processor
 */
class WP_HTML_Stack_Event {
	/**
	 * Refers to popping an element off of the stack of open elements.
	 *
	 * @since 6.6.0
	 */
	const POP = 'pop';

	/**
	 * Refers to pushing an element onto the stack of open elements.
	 *
	 * @since 6.6.0
	 */
	const PUSH = 'push';

	/**
	 * References the token associated with the stack push event,
	 * even if this is a pop event for that element.
	 *
	 * @since 6.6.0
	 *
	 * @var WP_HTML_Token
	 */
	public $token;

	/**
	 * Indicates which kind of stack operation this event represents.
	 *
	 * May be one of the class constants.
	 *
	 * @since 6.6.0
	 *
	 * @see self::POP
	 * @see self::PUSH
	 *
	 * @var string
	 */
	public $operation;

	/**
	 * Indicates if the stack element is a real or virtual node.
	 *
	 * @since 6.6.0
	 *
	 * @var string
	 */
	public $provenance;

	/**
	 * For the push event of a foster-parented node, indicates how many
	 * elements at the top of the stack of open elements (at the time of the
	 * push, starting below the pushed node) are bypassed by the node's
	 * document ancestry.
	 *
	 * A foster-parented node is inserted at a location before the table
	 * whose context it was found in: the enclosing table context remains
	 * open on the stack of open elements, but is not part of the fostered
	 * node's ancestor chain. This count is recorded when the push event is
	 * created because the stack may change before the event is visited.
	 *
	 * `null` for pop events and for nodes which were not foster-parented.
	 *
	 * @since 7.1.0
	 *
	 * @see https://html.spec.whatwg.org/#foster-parenting
	 *
	 * @var int|null
	 */
	public $foster_bypass_count = null;

	/**
	 * For a pop event of "real" provenance, references the token of the
	 * closing tag in the input HTML which produced the pop.
	 *
	 * The event's own token always references the opening token. When a pop
	 * event is deferred and visited later, the lexer must reposition to the
	 * closing tag's own syntax, whose location this token records.
	 *
	 * @since 7.1.0
	 *
	 * @var WP_HTML_Token|null
	 */
	public $closer_token = null;

	/**
	 * Indicates that this event was deferred in a table window and is being
	 * visited after the lexer moved past its syntax: visiting it requires
	 * repositioning the lexer to the event's token.
	 *
	 * @since 7.1.0
	 *
	 * @see WP_HTML_Processor::MAX_BUFFERED_TABLE_EVENTS
	 *
	 * @var bool
	 */
	public $is_deferred = false;

	/**
	 * Constructor function.
	 *
	 * @since 6.6.0
	 *
	 * @param WP_HTML_Token $token      Token associated with stack event, always an opening token.
	 * @param string        $operation  One of self::PUSH or self::POP.
	 * @param string        $provenance "virtual" or "real".
	 */
	public function __construct( WP_HTML_Token $token, string $operation, string $provenance ) {
		$this->token      = $token;
		$this->operation  = $operation;
		$this->provenance = $provenance;
	}
}
