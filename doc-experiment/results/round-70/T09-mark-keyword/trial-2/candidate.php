<?php

function mark_keyword( string $html, string $keyword ): string {
	if ( '' === $keyword ) {
		return WP_HTML_Processor::normalize( $html ) ?? $html;
	}

	$processor = WP_HTML_Processor::create_fragment( $html );
	if ( null === $processor ) {
		return WP_HTML_Processor::normalize( $html ) ?? $html;
	}

	$output = '';

	while ( $processor->next_token() ) {
		if ( '#text' === $processor->get_token_type() && str_contains( $processor->get_modifiable_text(), $keyword ) ) {
			$output .= '<mark>' . $processor->serialize_token() . '</mark>';
			continue;
		}

		$output .= $processor->serialize_token();
	}

	if ( null !== $processor->get_last_error() ) {
		return WP_HTML_Processor::normalize( $html ) ?? $html;
	}

	return $output;
}
