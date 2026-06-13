<?php
function remove_empty_paragraphs( string $html ): string {
	if ( ! class_exists( 'WP_HTML_Processor' ) ) {
		return $html;
	}

	$processor = WP_HTML_Processor::create_fragment( $html );
	if ( null === $processor ) {
		return $html;
	}

	$output = '';
	$buffer = '';
	$in_paragraph = false;
	$paragraph_has_content = false;

	while ( $processor->next_token() ) {
		$token_type = $processor->get_token_type();
		$token_name = $processor->get_token_name();
		$is_p_token = 'P' === $token_name;

		if ( $in_paragraph ) {
			if ( $is_p_token ) {
				$buffer .= $processor->serialize_token();

				if ( $processor->is_tag_closer() ) {
					if ( $paragraph_has_content ) {
						$output .= $buffer;
					}

					$buffer = '';
					$in_paragraph = false;
					$paragraph_has_content = false;
				}

				continue;
			}

			$buffer .= $processor->serialize_token();
			$paragraph_has_content = true;
			continue;
		}

		if ( $is_p_token && ! $processor->is_tag_closer() ) {
			$in_paragraph = true;
			$paragraph_has_content = false;
			$buffer = $processor->serialize_token();
			continue;
		}

		$output .= $processor->serialize_token();
	}

	if ( $in_paragraph ) {
		return $html;
	}

	if ( method_exists( $processor, 'get_last_error' ) && null !== $processor->get_last_error() ) {
		return $html;
	}

	if ( method_exists( $processor, 'paused_at_incomplete_token' ) && $processor->paused_at_incomplete_token() ) {
		return $html;
	}

	return $output;
}
