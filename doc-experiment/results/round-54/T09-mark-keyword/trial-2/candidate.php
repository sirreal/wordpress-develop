<?php

function mark_keyword( string $html, string $keyword ): string {
	$processor = WP_HTML_Processor::create_fragment( $html );

	if ( null === $processor ) {
		$normalized = WP_HTML_Processor::normalize( $html );
		return null !== $normalized ? $normalized : $html;
	}

	$output = '';

	while ( $processor->next_token() ) {
		if ( '#text' === $processor->get_token_type() ) {
			$text = $processor->get_modifiable_text();

			if ( '' !== $keyword && false !== strpos( $text, $keyword ) ) {
				$output .= '<mark>' . htmlspecialchars( $text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' ) . '</mark>';
				continue;
			}
		}

		$output .= $processor->serialize_token();
	}

	if ( null !== $processor->get_last_error() || $processor->paused_at_incomplete_token() ) {
		$normalized = WP_HTML_Processor::normalize( $html );
		return null !== $normalized ? $normalized : $output;
	}

	return $output;
}
