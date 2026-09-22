<?php
/**
 * Extract the text content of the first H1 element in an HTML fragment.
 *
 * @param string $html HTML fragment.
 * @return string|null Text content of the first H1, or null if none exists.
 */
function get_first_h1_text( string $html ): ?string {
	if ( ! class_exists( 'WP_HTML_Processor' ) ) {
		return null;
	}

	$processor = WP_HTML_Processor::create_fragment( $html );
	if ( null === $processor ) {
		return null;
	}

	while ( $processor->next_tag( 'H1' ) ) {
		if ( $processor->is_tag_closer() ) {
			continue;
		}

		$h1_depth = $processor->get_current_depth();
		$text     = '';

		while ( $processor->next_token() ) {
			if ( $processor->get_current_depth() < $h1_depth ) {
				break;
			}

			if ( '#text' === $processor->get_token_type() ) {
				$text .= $processor->get_modifiable_text();
			}
		}

		return $text;
	}

	return null;
}
