<?php

function extract_toc( string $html ): array {
	$processor = WP_HTML_Processor::create_fragment( $html );
	if ( null === $processor ) {
		return array();
	}

	$toc = array();
	$current_heading_level = null;
	$current_heading_depth = null;
	$current_heading_text = '';

	while ( $processor->next_token() ) {
		$token_type = $processor->get_token_type();
		$token_name = $processor->get_token_name();
		$current_depth = $processor->get_current_depth();

		if ( null !== $current_heading_level && $current_depth < $current_heading_depth ) {
			$toc[] = array(
				'level' => $current_heading_level,
				'text'  => $current_heading_text,
			);

			$current_heading_level = null;
			$current_heading_depth = null;
			$current_heading_text = '';
		}

		if ( null !== $current_heading_level ) {
			if ( '#text' === $token_type ) {
				$current_heading_text .= $processor->get_modifiable_text();
			}
			continue;
		}

		if ( '#tag' !== $token_type || $processor->is_tag_closer() || null === $token_name ) {
			continue;
		}

		if ( ! preg_match( '/^H([1-6])$/', $token_name, $matches ) ) {
			continue;
		}

		$current_heading_level = (int) $matches[1];
		$current_heading_depth = $current_depth;
		$current_heading_text = '';
	}

	if ( null !== $current_heading_level ) {
		$toc[] = array(
			'level' => $current_heading_level,
			'text'  => $current_heading_text,
		);
	}

	return $toc;
}
