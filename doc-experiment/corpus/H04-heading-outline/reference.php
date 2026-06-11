<?php

function heading_outline( string $html ): array {
	$processor = WP_HTML_Processor::create_fragment( $html );
	if ( null === $processor ) {
		return array();
	}

	$headings      = array( 'H1', 'H2', 'H3', 'H4', 'H5', 'H6' );
	$outline       = array();
	$current_level = null;
	$current_text  = '';
	$heading_depth = null;

	while ( $processor->next_token() ) {
		$token_name = $processor->get_token_name();

		if ( null !== $current_level ) {
			if ( '#text' === $processor->get_token_type() ) {
				$current_text .= $processor->get_modifiable_text();
				continue;
			}
			if ( $processor->get_current_depth() < $heading_depth ) {
				$outline[]     = array(
					'level' => $current_level,
					'text'  => $current_text,
				);
				$current_level = null;
				$current_text  = '';
			}
			continue;
		}

		if (
			'#tag' === $processor->get_token_type() &&
			! $processor->is_tag_closer() &&
			in_array( $token_name, $headings, true )
		) {
			$current_level = (int) $token_name[1];
			$current_text  = '';
			$heading_depth = $processor->get_current_depth();
		}
	}

	if ( null !== $current_level ) {
		$outline[] = array(
			'level' => $current_level,
			'text'  => $current_text,
		);
	}

	return $outline;
}
