<?php

function remove_empty_paragraphs( string $html ): string {
	$processor = WP_HTML_Processor::create_fragment( $html );

	if ( null === $processor ) {
		return $html;
	}

	$output          = '';
	$pending_p       = null;
	$pending_p_depth = 0;

	while ( $processor->next_token() ) {
		if ( null !== $pending_p ) {
			if ( 'P' === $processor->get_tag() && $processor->is_tag_closer() && $processor->get_current_depth() === $pending_p_depth - 1 ) {
				$pending_p       = null;
				$pending_p_depth = 0;
				continue;
			}

			$output .= $pending_p;
			$pending_p = null;
			$pending_p_depth = 0;
		}

		if ( '#tag' === $processor->get_token_type() && 'P' === $processor->get_tag() && ! $processor->is_tag_closer() ) {
			$pending_p       = $processor->serialize_token();
			$pending_p_depth = $processor->get_current_depth();
			continue;
		}

		$output .= $processor->serialize_token();
	}

	if ( $processor->paused_at_incomplete_token() || null !== $processor->get_last_error() ) {
		return $html;
	}

	if ( null !== $pending_p ) {
		$output .= $pending_p;
	}

	return $output;
}
