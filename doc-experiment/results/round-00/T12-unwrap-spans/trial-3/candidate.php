<?php

function unwrap_spans( string $html ): string {
	$processor = WP_HTML_Processor::create_fragment( $html );
	if ( null === $processor ) {
		return '';
	}

	$output = '';

	while ( $processor->next_token() ) {
		// Skip SPAN tags (both openers and closers) — their content remains.
		if ( 'SPAN' === $processor->get_tag() ) {
			continue;
		}

		$output .= $processor->serialize_token();
	}

	$normalized = WP_HTML_Processor::normalize( $output );

	return null !== $normalized ? $normalized : $output;
}
