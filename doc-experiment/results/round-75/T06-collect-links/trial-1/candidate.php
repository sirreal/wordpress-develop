<?php

function collect_links( string $html ): array {
	$processor = WP_HTML_Processor::create_fragment( $html );
	if ( null === $processor ) {
		return array();
	}

	$links = array();
	$stack = array();

	while ( $processor->next_token() ) {
		if ( '#text' === $processor->get_token_type() ) {
			$text = $processor->get_modifiable_text();

			foreach ( $stack as $link_index ) {
				if ( null !== $link_index ) {
					$links[ $link_index ]['text'] .= $text;
				}
			}

			continue;
		}

		if ( '#tag' !== $processor->get_token_type() || 'A' !== $processor->get_tag() ) {
			continue;
		}

		if ( $processor->is_tag_closer() ) {
			array_pop( $stack );
			continue;
		}

		$href = $processor->get_attribute( 'href' );
		if ( is_string( $href ) ) {
			$links[] = array(
				'href' => $href,
				'text' => '',
			);
			$stack[] = array_key_last( $links );
		} else {
			$stack[] = null;
		}
	}

	return $links;
}
