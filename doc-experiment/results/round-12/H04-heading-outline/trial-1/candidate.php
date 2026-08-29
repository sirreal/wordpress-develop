<?php

/**
 * Build a heading outline from HTML.
 *
 * Given an HTML fragment, returns a list of all headings (H1-H6) in document order.
 * Each heading entry contains the level (1-6) and concatenated text content with
 * decoded character references.
 *
 * @param string $html HTML fragment to parse.
 * @return array Array of heading entries, each with 'level' and 'text' keys, or empty array.
 */
function heading_outline( string $html ): array {
	$processor = WP_HTML_Processor::create_fragment( $html );
	if ( null === $processor ) {
		return array();
	}

	$headings = array();

	while ( $processor->next_token() ) {
		$token_name = $processor->get_token_name();
		$token_type = $processor->get_token_type();

		// Check if this is a heading opening tag
		if ( '#tag' === $token_type && ! $processor->is_tag_closer() ) {
			$level = null;

			// Determine heading level (H1-H6)
			if ( 'H1' === $token_name ) {
				$level = 1;
			} elseif ( 'H2' === $token_name ) {
				$level = 2;
			} elseif ( 'H3' === $token_name ) {
				$level = 3;
			} elseif ( 'H4' === $token_name ) {
				$level = 4;
			} elseif ( 'H5' === $token_name ) {
				$level = 5;
			} elseif ( 'H6' === $token_name ) {
				$level = 6;
			}

			if ( null !== $level ) {
				// Collect text content inside the heading
				$text = '';
				$depth_inside_heading = $processor->get_current_depth();

				// Iterate through tokens inside the heading
				while ( $processor->next_token() ) {
					$current_depth = $processor->get_current_depth();

					// Stop when we exit the heading
					if ( $current_depth < $depth_inside_heading ) {
						break;
					}

					// Collect text from #text nodes inside the heading
					if ( '#text' === $processor->get_token_type() ) {
						$text .= $processor->get_modifiable_text();
					}
				}

				$headings[] = array(
					'level' => $level,
					'text'  => $text,
				);
			}
		}
	}

	return $headings;
}
