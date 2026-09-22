<?php

function extract_toc( string $html ): array {
	$processor = WP_HTML_Processor::create_fragment( $html );
	if ( null === $processor ) {
		return array();
	}

	$toc = array();
	$current = null;

	while ( $processor->next_token() ) {
		$token_type = $processor->get_token_type();
		$tag_name   = $processor->get_tag();

		if ( null !== $current ) {
			if ( '#text' === $token_type ) {
				$current['text'] .= $processor->get_modifiable_text();
				continue;
			}

			if ( '#tag' === $token_type && $processor->is_tag_closer() && $tag_name === $current['tag_name'] ) {
				$toc[] = array(
					'level' => $current['level'],
					'text'  => $current['text'],
				);
				$current = null;
			}
		}

		if ( null !== $current ) {
			continue;
		}

		if ( '#tag' !== $token_type || $processor->is_tag_closer() || null === $tag_name ) {
			continue;
		}

		if ( preg_match( '/^H([1-6])$/', $tag_name, $matches ) ) {
			$current = array(
				'level'    => (int) $matches[1],
				'text'     => '',
				'tag_name' => $tag_name,
			);
		}
	}

	return $toc;
}
