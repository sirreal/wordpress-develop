<?php

function extract_toc( string $html ): array {
	$processor = \WP_HTML_Processor::create_fragment( $html );

	if ( null === $processor ) {
		return array();
	}

	$toc            = array();
	$heading_level  = null;
	$heading_depth  = null;
	$heading_text   = '';

	while ( $processor->next_token() ) {
		if ( null !== $heading_level && $processor->get_current_depth() < $heading_depth ) {
			$toc[] = array(
				'level' => $heading_level,
				'text'  => $heading_text,
			);

			$heading_level = null;
			$heading_depth = null;
			$heading_text  = '';
		}

		if ( null !== $heading_level && '#text' === $processor->get_token_type() ) {
			$heading_text .= $processor->get_modifiable_text();
			continue;
		}

		if ( '#tag' !== $processor->get_token_type() || $processor->is_tag_closer() ) {
			continue;
		}

		$tag = $processor->get_tag();
		if ( null === $tag || ! in_array( $tag, array( 'H1', 'H2', 'H3', 'H4', 'H5', 'H6' ), true ) ) {
			continue;
		}

		$heading_level = (int) substr( $tag, 1 );
		$heading_depth = $processor->get_current_depth();
		$heading_text  = '';
	}

	if ( null !== $heading_level ) {
		$toc[] = array(
			'level' => $heading_level,
			'text'  => $heading_text,
		);
	}

	return $toc;
}
