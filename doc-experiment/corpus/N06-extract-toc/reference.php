<?php

function extract_toc( string $html ): array {
	$processor = WP_HTML_Processor::create_fragment( $html );
	if ( null === $processor ) {
		return array();
	}

	$toc = array();
	while ( $processor->next_tag() ) {
		$tag_name = $processor->get_tag();
		if ( ! in_array( $tag_name, array( 'H1', 'H2', 'H3', 'H4', 'H5', 'H6' ), true ) ) {
			continue;
		}

		$depth = $processor->get_current_depth();
		$text  = '';
		while ( $processor->next_token() && $processor->get_current_depth() >= $depth ) {
			if ( '#text' === $processor->get_token_type() ) {
				$text .= $processor->get_modifiable_text();
			}
		}

		$toc[] = array(
			'level' => (int) substr( $tag_name, 1 ),
			'text'  => $text,
		);
	}

	return $toc;
}
