<?php

function heading_outline( string $html ): array {
	$processor = WP_HTML_Processor::create_fragment( $html );
	if ( null === $processor ) {
		return array();
	}

	$outline        = array();
	$in_heading     = false;
	$heading_level  = 0;
	$heading_depth  = 0;
	$heading_text   = '';

	while ( $processor->next_token() ) {
		$token_type = $processor->get_token_type();

		if ( '#tag' === $token_type ) {
			$tag_name = $processor->get_tag();

			if ( $in_heading ) {
				// Check if we've closed the heading by depth returning to heading_depth - 1
				// After the closer is processed, depth returns to heading_depth - 1.
				// But we detect the closer tag explicitly:
				if ( $processor->is_tag_closer() && $processor->get_current_depth() === $heading_depth - 1 ) {
					// We've closed the heading element itself.
					$outline[] = array(
						'level' => $heading_level,
						'text'  => $heading_text,
					);
					$in_heading    = false;
					$heading_level = 0;
					$heading_depth = 0;
					$heading_text  = '';
				}
				// Otherwise, we're inside the heading; text nodes will be captured below.
			} else {
				// Check if this is an opening heading tag H1-H6.
				if (
					! $processor->is_tag_closer() &&
					null !== $tag_name &&
					1 === preg_match( '/^H([1-6])$/', $tag_name, $matches )
				) {
					$in_heading    = true;
					$heading_level = (int) $matches[1];
					$heading_depth = $processor->get_current_depth();
					$heading_text  = '';
				}
			}
		} elseif ( '#text' === $token_type && $in_heading ) {
			$heading_text .= $processor->get_modifiable_text();
		}
	}

	return $outline;
}
