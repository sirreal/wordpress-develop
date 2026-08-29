<?php

declare(strict_types=1);

function mark_keyword( string $html, string $keyword ): string {
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

	if ( null !== $processor->get_last_error() || $processor->paused_at_incomplete_token() ) {
		return WP_HTML_Processor::normalize( $html ) ?? $html;
	}

	return $output;
}
