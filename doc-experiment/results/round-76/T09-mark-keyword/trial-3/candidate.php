<?php

function mark_keyword( string $html, string $keyword ): string {
	$processor = WP_HTML_Processor::create_fragment( $html );
	if ( null === $processor ) {
		return WP_HTML_Processor::normalize( $html ) ?? $html;
	}

	$output        = '';
	$special_tags  = array( 'SCRIPT', 'STYLE', 'TITLE', 'TEXTAREA' );

	while ( $processor->next_token() ) {
		$token_type = $processor->get_token_type();
		$token_name = $processor->get_token_name();

		if ( '#text' === $token_type ) {
			$text = $processor->get_modifiable_text();
			if ( '' !== $keyword && false !== strpos( $text, $keyword ) ) {
				$output .= '<mark>' . $processor->serialize_token() . '</mark>';
				continue;
			}
		} elseif ( '#tag' === $token_type && ! $processor->is_tag_closer() && in_array( $token_name, $special_tags, true ) ) {
			// Special text-bearing elements expose their contents on the opener token.
			// We intentionally do not wrap them for this task.
			$processor->get_modifiable_text();
		}

		$output .= $processor->serialize_token();
	}

	if ( null !== $processor->get_last_error() ) {
		return WP_HTML_Processor::normalize( $html ) ?? $output;
	}

	return $output;
}
