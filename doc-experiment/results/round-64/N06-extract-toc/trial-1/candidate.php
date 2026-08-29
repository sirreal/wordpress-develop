<?php
declare(strict_types=1);

function extract_toc( string $html ): array {
	$processor = WP_HTML_Processor::create_fragment( $html );
	if ( null === $processor ) {
		return array();
	}

	$toc = array();

	$active_heading_level = null;
	$active_heading_depth  = null;
	$active_heading_text   = '';

	while ( $processor->next_token() ) {
		$token_type = $processor->get_token_type();

		if ( null !== $active_heading_level ) {
			$current_depth = $processor->get_current_depth();

			if ( '#text' === $token_type ) {
				$active_heading_text .= $processor->get_modifiable_text();
			}

			if ( $current_depth < $active_heading_depth ) {
				$toc[] = array(
					'level' => $active_heading_level,
					'text'  => $active_heading_text,
				);

				$active_heading_level = null;
				$active_heading_depth = null;
				$active_heading_text  = '';
			}

			continue;
		}

		if ( '#tag' !== $token_type || $processor->is_tag_closer() ) {
			continue;
		}

		$tag_name = $processor->get_tag();
		if ( null === $tag_name || 2 !== strlen( $tag_name ) || 'H' !== $tag_name[0] ) {
			continue;
		}

		$level_char = $tag_name[1];
		if ( $level_char < '1' || $level_char > '6' ) {
			continue;
		}

		$active_heading_level = (int) $level_char;
		$active_heading_depth  = $processor->get_current_depth();
		$active_heading_text   = '';
	}

	if ( null !== $active_heading_level ) {
		$toc[] = array(
			'level' => $active_heading_level,
			'text'  => $active_heading_text,
		);
	}

	return $toc;
}
