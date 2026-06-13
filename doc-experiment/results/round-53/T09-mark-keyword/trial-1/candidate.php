<?php

function mark_keyword( string $html, string $keyword ): string {
	$processor = WP_HTML_Processor::create_fragment( $html );

	if ( null === $processor ) {
		return $html;
	}

	$output = '';

	while ( $processor->next_token() ) {
		if ( '#text' === $processor->get_token_type() && '' !== $keyword && str_contains( $processor->get_modifiable_text(), $keyword ) ) {
			$output .= '<mark>';
			$output .= $processor->serialize_token();
			$output .= '</mark>';
			continue;
		}

		$output .= $processor->serialize_token();
	}

	if ( null !== $processor->get_last_error() ) {
		return $html;
	}

	return $output;
}
