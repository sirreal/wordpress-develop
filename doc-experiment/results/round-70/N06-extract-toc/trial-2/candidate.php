<?php
function extract_toc( string $html ): array {
	$processor = WP_HTML_Processor::create_fragment( $html );
	if ( null === $processor ) {
		return array();
	}

	$toc = array();

	while ( $processor->next_tag() ) {
		$tag = $processor->get_tag();
		if ( null === $tag ) {
			continue;
		}

		if ( 'H1' !== $tag && 'H2' !== $tag && 'H3' !== $tag && 'H4' !== $tag && 'H5' !== $tag && 'H6' !== $tag ) {
			continue;
		}

		if ( $processor->is_tag_closer() ) {
			continue;
		}

		$level = (int) substr( $tag, 1 );
		$depth = $processor->get_current_depth();
		$text  = '';

		while ( $processor->next_token() && $processor->get_current_depth() >= $depth ) {
			if ( '#text' === $processor->get_token_type() ) {
				$text .= $processor->get_modifiable_text();
			}
		}

		$toc[] = array(
			'level' => $level,
			'text'  => $text,
		);
	}

	return $toc;
}
