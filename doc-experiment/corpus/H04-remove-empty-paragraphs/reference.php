<?php

function remove_empty_paragraphs( string $html ): string {
	$processor = WP_HTML_Processor::create_fragment( $html );
	if ( null === $processor ) {
		return $html;
	}

	$output      = '';
	$has_current = false;

	while ( $has_current || $processor->next_token() ) {
		$has_current = false;

		if (
			'#tag' !== $processor->get_token_type() ||
			$processor->is_tag_closer() ||
			'P' !== $processor->get_tag() ||
			'html' !== $processor->get_namespace()
		) {
			$output .= $processor->serialize_token();
			continue;
		}

		$paragraph_opener = $processor->serialize_token();

		while ( true ) {
			if ( ! $processor->next_token() ) {
				if ( null !== $processor->get_last_error() || $processor->paused_at_incomplete_token() ) {
					return $html;
				}

				$output .= $paragraph_opener;
				break 2;
			}

			if (
				'#tag' === $processor->get_token_type() &&
				$processor->is_tag_closer() &&
				'P' === $processor->get_tag() &&
				'html' === $processor->get_namespace()
			) {
				continue 2;
			}

			// Ignore tokens that disappear from normalized output, e.g. #presumptuous-tag.
			if ( '' === $processor->serialize_token() ) {
				continue;
			}

			$output      .= $paragraph_opener;
			$has_current = true;
			continue 2;
		}
	}

	return ( null === $processor->get_last_error() && ! $processor->paused_at_incomplete_token() )
		? $output
		: $html;
}
