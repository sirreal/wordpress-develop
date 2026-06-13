<?php

function unwrap_spans( string $html ): string {
	if ( ! class_exists( 'WP_HTML_Processor' ) ) {
		return $html;
	}

	$processor = WP_HTML_Processor::create_fragment( $html );
	if ( null === $processor ) {
		return $html;
	}

	$output = '';
	while ( $processor->next_token() ) {
		if ( 'SPAN' === $processor->get_tag() ) {
			continue;
		}

		$output .= $processor->serialize_token();
	}

	if ( null !== $processor->get_last_error() ) {
		return $html;
	}

	return $output;
}
