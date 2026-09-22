<?php

function heading_outline( string $html ): array {
	$processor = new WP_HTML_Tag_Processor( $html );
	$headings = array();
	$current_heading = null;
	$heading_depth = 0;

	while ( $processor->next_token() ) {
		$token_type = $processor->get_token_type();
		$tag_name = $processor->get_tag();

		// Check if we're opening a heading tag
		if ( '#tag' === $token_type && ! $processor->is_tag_closer() ) {
			if ( in_array( $tag_name, array( 'H1', 'H2', 'H3', 'H4', 'H5', 'H6' ), true ) ) {
				if ( null === $current_heading ) {
					$level = (int) substr( $tag_name, 1 );
					$current_heading = array(
						'level' => $level,
						'text'  => '',
					);
					$heading_depth = 1;
				} else {
					$heading_depth++;
				}
			} elseif ( null !== $current_heading ) {
				$heading_depth++;
			}
		}
		// Collect text content while inside a heading
		elseif ( '#text' === $token_type && null !== $current_heading ) {
			$current_heading['text'] .= $processor->get_modifiable_text();
		}
		// Check if we're closing a tag
		elseif ( '#tag' === $token_type && $processor->is_tag_closer() ) {
			if ( null !== $current_heading ) {
				if ( in_array( $tag_name, array( 'H1', 'H2', 'H3', 'H4', 'H5', 'H6' ), true ) ) {
					if ( $heading_depth === 1 ) {
						$headings[] = $current_heading;
						$current_heading = null;
						$heading_depth = 0;
					} else {
						$heading_depth--;
					}
				} else {
					$heading_depth--;
				}
			}
		}
	}

	return $headings;
}