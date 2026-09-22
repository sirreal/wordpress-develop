<?php

if ( ! function_exists( 'unwrap_spans' ) ) {
	function unwrap_spans( string $html ): string {
		$processor = WP_HTML_Processor::create_fragment( $html );
		if ( null === $processor ) {
			$normalized = WP_HTML_Processor::normalize( $html );

			return null !== $normalized ? $normalized : $html;
		}

		$output = '';

		while ( $processor->next_token() ) {
			if ( 'SPAN' === $processor->get_tag() ) {
				continue;
			}

			$output .= $processor->serialize_token();
		}

		if ( null !== $processor->get_last_error() ) {
			$normalized = WP_HTML_Processor::normalize( $html );

			return null !== $normalized ? $normalized : $output;
		}

		return $output;
	}
}
