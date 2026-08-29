<?php

declare(strict_types=1);

function mark_keyword( string $html, string $keyword ): string {
	if ( '' === $keyword ) {
		$normalized = WP_HTML_Processor::normalize( $html );
		return null !== $normalized ? $normalized : $html;
	}

	$processor = WP_HTML_Processor::create_fragment( $html );
	if ( null === $processor ) {
		$normalized = WP_HTML_Processor::normalize( $html );
		return null !== $normalized ? $normalized : $html;
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
		$normalized = WP_HTML_Processor::normalize( $html );
		return null !== $normalized ? $normalized : $html;
	}

	return $output;
}
