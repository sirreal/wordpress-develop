<?php

function mark_keyword( string $html, string $keyword ): string {
	$processor = WP_HTML_Processor::create_fragment( $html );
	if ( null === $processor ) {
		return $html;
	}

	$output = '';

	while ( $processor->next_token() ) {
		if ( null !== $processor->get_last_error() ) {
			return $html;
		}

		$token_type = $processor->get_token_type();

		if ( '#text' === $token_type ) {
			$decoded_text    = $processor->get_modifiable_text();
			$serialized_text = $processor->serialize_token();

			if ( false !== strpos( $decoded_text, $keyword ) ) {
				$output .= '<mark>' . $serialized_text . '</mark>';
			} else {
				$output .= $serialized_text;
			}
		} else {
			$output .= $processor->serialize_token();
		}
	}

	if ( null !== $processor->get_last_error() ) {
		return $html;
	}

	return $output;
}
