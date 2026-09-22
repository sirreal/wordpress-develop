<?php

function mark_keyword( string $html, string $keyword ): string {
	if ( '' === $keyword || ! class_exists( 'WP_HTML_Processor' ) ) {
		return $html;
	}

	$processor = WP_HTML_Processor::create_fragment( $html );
	if ( null === $processor ) {
		return $html;
	}

	$output = '';

	while ( $processor->next_token() ) {
		if ( '#text' === $processor->get_token_type() && false !== strpos( $processor->get_modifiable_text(), $keyword ) ) {
			$output .= '<mark>' . $processor->serialize_token() . '</mark>';
			continue;
		}

		$output .= $processor->serialize_token();
	}

	if ( null !== $processor->get_last_error() ) {
		return $html;
	}

	return $output;
}
