<?php

function unwrap_spans( string $html ): string {
	$processor = WP_HTML_Processor::create_fragment( $html );
	if ( null === $processor ) {
		return '';
	}

	$output = '';
	while ( $processor->next_token() ) {
		$tag_name = $processor->get_tag();

		// Skip SPAN openers and closers; keep all other tokens.
		if ( 'SPAN' === $tag_name ) {
			continue;
		}

		$output .= $processor->serialize_token();
	}

	// Normalize the output (the task requires normalized serialization).
	$normalized = WP_HTML_Processor::normalize( $output );
	return null !== $normalized ? $normalized : $output;
}
