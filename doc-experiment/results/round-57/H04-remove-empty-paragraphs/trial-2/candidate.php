<?php

function remove_empty_paragraphs( string $html ): string {
	$processor = WP_HTML_Processor::create_fragment( $html );
	if ( null === $processor ) {
		return $html;
	}

	$output              = '';
	$buffer              = '';
	$buffering_paragraph  = false;
	$paragraph_depth      = null;
	$paragraph_has_content = false;

	while ( $processor->next_token() ) {
		$token_html = $processor->serialize_token();

		if ( ! $buffering_paragraph ) {
			if ( 'P' === $processor->get_token_name() && ! $processor->is_tag_closer() ) {
				$buffer               = $token_html;
				$buffering_paragraph   = true;
				$paragraph_depth       = $processor->get_current_depth();
				$paragraph_has_content = false;
				continue;
			}

			$output .= $token_html;
			continue;
		}

		$buffer .= $token_html;

		if ( 'P' === $processor->get_token_name() && $processor->is_tag_closer() && $processor->get_current_depth() < $paragraph_depth ) {
			if ( $paragraph_has_content ) {
				$output .= $buffer;
			}

			$buffer               = '';
			$buffering_paragraph   = false;
			$paragraph_depth       = null;
			$paragraph_has_content = false;
			continue;
		}

		$paragraph_has_content = true;
	}

	if ( null !== $processor->get_last_error() || $processor->paused_at_incomplete_token() ) {
		return $html;
	}

	if ( $buffering_paragraph ) {
		$output .= $buffer;
	}

	return $output;
}
