<?php

function extract_toc( string $html ): array {
	if ( ! class_exists( 'WP_HTML_Processor' ) ) {
		return array();
	}

	$processor = WP_HTML_Processor::create_fragment( $html );
	if ( null === $processor ) {
		return array();
	}

	$toc = array();

	$current_heading_tag   = null;
	$current_heading_depth  = null;
	$current_heading_text   = '';

	while ( $processor->next_token() ) {
		$token_type = $processor->get_token_type();

		if ( null !== $current_heading_tag ) {
			if ( '#text' === $token_type ) {
				$current_heading_text .= $processor->get_modifiable_text();
				continue;
			}

			if ( '#tag' === $token_type && $processor->is_tag_closer() && $processor->get_tag() === $current_heading_tag ) {
				$toc[] = array(
					'level' => (int) substr( $current_heading_tag, 1 ),
					'text'  => $current_heading_text,
				);

				$current_heading_tag  = null;
				$current_heading_depth = null;
				$current_heading_text  = '';
			}
		}

		if ( '#tag' !== $token_type || $processor->is_tag_closer() ) {
			continue;
		}

		$tag = $processor->get_tag();
		if ( null === $tag || 2 !== preg_match( '/^H([1-6])$/', $tag ) ) {
			continue;
		}

		if ( null !== $current_heading_tag ) {
			continue;
		}

		$current_heading_tag  = $tag;
		$current_heading_depth = $processor->get_current_depth();
		$current_heading_text  = '';
	}

	if ( null !== $current_heading_tag ) {
		$toc[] = array(
			'level' => (int) substr( $current_heading_tag, 1 ),
			'text'  => $current_heading_text,
		);
	}

	return $toc;
}
