<?php
namespace CssSelectorFuzz;

/**
 * Captures the processor's own view of a document as the matching oracle's
 * ground truth, so arbitrary (restructured, foster-parented, misnested)
 * HTML can be fuzzed without a hand-built model.
 *
 * The capture is a flat list of rows in VISIT order — the order next_tag()
 * (and therefore select()) encounters elements, which for restructured
 * content is token order, not final-DOM document order. Context selectors
 * in the supported grammar are type-only, so per element the matcher needs
 * only the tag, the attributes, and the ancestor tag list — exactly what
 * get_breadcrumbs() provides at each visit. Virtual (implied) elements are
 * visited too and captured with a placeholder fid.
 *
 * Row shape:
 *   html row: array( tag: string, fid: string, attrs: array<[name, value]>,
 *                    ancestorTags: string[] nearest-first )
 *   tag row:  same without ancestorTags.
 */
class TreeCapture {

	const CAPTURE_ITERATION_LIMIT = 20000;

	/**
	 * Captures the processor's view of a document or a fragment.
	 *
	 * @param string      $html    The markup ( full document or fragment ).
	 * @param string|null $context When set, parse as a fragment in this
	 *                             context ( e.g. '<body>' ); the tag
	 *                             processor has no fragment mode, so tagRows
	 *                             is null in that case.
	 * @return array{
	 *     htmlRows: array|null,
	 *     tagRows: array|null,
	 *     quirks: bool,
	 *     error: string|null,
	 * }
	 */
	public static function capture( string $html, ?string $context = null ): array {
		$out = array(
			'htmlRows' => null,
			'tagRows'  => null,
			'quirks'   => false,
			'error'    => null,
		);

		$processor = null === $context
			? \WP_HTML_Processor::create_full_parser( $html )
			: \WP_HTML_Processor::create_fragment( $html, $context );
		if ( null === $processor ) {
			$out['error'] = 'fragment-context-unsupported';
			return $out;
		}
		$rows       = array();
		$iterations = 0;
		while ( $processor->next_tag() ) {
			if ( ++$iterations > self::CAPTURE_ITERATION_LIMIT ) {
				$out['error'] = 'html-capture-iteration-limit';
				return $out;
			}
			$breadcrumbs = $processor->get_breadcrumbs();
			array_pop( $breadcrumbs );
			$rows[] = array(
				'tag'          => (string) $processor->get_tag(),
				'fid'          => self::fid_of( $processor ),
				'attrs'        => self::attrs_of( $processor ),
				'ancestorTags' => array_reverse( $breadcrumbs ),
				'namespace'    => $processor->get_namespace(),
			);
		}

		if ( null !== $processor->get_last_error() ) {
			$out['error'] = 'html-processor-error: ' . $processor->get_last_error();
			return $out;
		}
		if ( null !== $processor->get_unsupported_exception() ) {
			$out['error'] = 'html-processor-unsupported: ' . $processor->get_unsupported_exception()->getMessage();
			return $out;
		}

		$out['htmlRows'] = $rows;
		$out['quirks']   = $processor->is_quirks_mode();

		// The tag processor has no fragment mode; a fragment case exercises
		// the html processor's select() only.
		if ( null !== $context ) {
			return $out;
		}

		$tag_processor = new \WP_HTML_Tag_Processor( $html );
		$tag_rows      = array();
		$iterations    = 0;
		while ( $tag_processor->next_tag() ) {
			if ( ++$iterations > self::CAPTURE_ITERATION_LIMIT ) {
				$out['error'] = 'tag-capture-iteration-limit';
				return $out;
			}
			$tag_rows[] = array(
				'tag'   => (string) $tag_processor->get_tag(),
				'fid'   => self::fid_of( $tag_processor ),
				'attrs' => self::attrs_of( $tag_processor ),
			);
		}
		$out['tagRows'] = $tag_rows;

		return $out;
	}

	/** The element's data-fid, or the same placeholder collect_matches() uses. */
	private static function fid_of( $processor ): string {
		$fid = $processor->get_attribute( 'data-fid' );
		return is_string( $fid ) ? self::sanitize_fid( $fid ) : '(missing-fid:' . $processor->get_tag() . ')';
	}

	/**
	 * Replaces the lexbor protocol framing bytes ( TAB / LF / CR ) in a fid
	 * with '?'. Generated fids never contain these, but the lexbor harness
	 * applies the same replacement, so matching this here keeps the two trees
	 * comparable even for a hypothetical control-char fid ( the worst case is
	 * a benign tree-gated skip, never a false divergence ).
	 */
	public static function sanitize_fid( string $fid ): string {
		return strtr( $fid, "\t\n\r", '???' );
	}

	/**
	 * All attributes as ( lowercase name, decoded value ) pairs, excluding
	 * data-fid ( stored separately, mirroring the generated model's shape ).
	 *
	 * @return array<int, array{0: string, 1: string|true}>
	 */
	private static function attrs_of( $processor ): array {
		$attrs = array();
		foreach ( (array) $processor->get_attribute_names_with_prefix( '' ) as $name ) {
			if ( 'data-fid' === $name ) {
				continue;
			}
			$value   = $processor->get_attribute( $name );
			$attrs[] = array( $name, true === $value ? true : (string) $value );
		}
		return $attrs;
	}
}
