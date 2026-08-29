<?php
function extract_toc( string $html ): array {
	$processor = WP_HTML_Processor::create_fragment( $html );

	if ( null === $processor ) {
		return array();
	}

	$toc = array();

	while ( $processor->next_tag( array( 'tag_closers' => 'visit' ) ) ) {
		$tag = $processor->get_tag();
		if ( null === $tag || ! preg_match( '/^H([1-6])$/', $tag, $matches ) ) {
			continue;
		}

		if ( $processor->is_tag_closer() ) {
			continue;
		}

		$level = (int) $matches[1];
		$depth = $processor->get_current_depth();
		$text  = '';

		while ( $processor->next_token() ) {
			if ( $processor->get_current_depth() < $depth ) {
				break;
			}

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
