<?php

function heading_outline( string $html ): array {
	$processor = WP_HTML_Processor::create_fragment( $html );
	if ( null === $processor ) {
		return array();
	}

	$outline          = array();
	$in_heading       = false;
	$heading_tag      = null;
	$heading_depth    = null;
	$heading_text     = '';

	while ( $processor->next_token() ) {
		$token_type = $processor->get_token_type();

		if ( '#tag' === $token_type ) {
			$tag    = $processor->get_tag();
			$closer = $processor->is_tag_closer();
			$depth  = $processor->get_current_depth();

			if ( ! $in_heading ) {
				// Look for an opening heading tag.
				if (
					! $closer &&
					in_array( $tag, array( 'H1', 'H2', 'H3', 'H4', 'H5', 'H6' ), true )
				) {
					$in_heading    = true;
					$heading_tag   = $tag;
					$heading_depth = $depth;
					$heading_text  = '';
				}
			} else {
				// We're inside a heading; look for its closer.
				if (
					$closer &&
					$tag === $heading_tag &&
					$depth === $heading_depth
				) {
					// End of the heading.
					$level     = (int) $heading_tag[1];
					$outline[] = array(
						'level' => $level,
						'text'  => $heading_text,
					);
					$in_heading    = false;
					$heading_tag   = null;
					$heading_depth = null;
					$heading_text  = '';
				}
				// Ignore other tags inside the heading.
			}
		} elseif ( '#text' === $token_type && $in_heading ) {
			$heading_text .= $processor->get_modifiable_text();
		}
	}

	return $outline;
}
