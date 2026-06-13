<?php

function extract_toc( string $html ): array {
	$processor = WP_HTML_Processor::create_fragment( $html );
	if ( null === $processor ) {
		return array();
	}

	$toc   = array();
	$stack = array();

	while ( $processor->next_token() ) {
		$token_type = $processor->get_token_type();

		if ( '#tag' === $token_type ) {
			if ( ! $processor->is_tag_closer() ) {
				$tag = $processor->get_tag();
				if ( null !== $tag && preg_match( '/^H[1-6]$/', $tag ) ) {
					$stack[] = array(
						'level' => (int) substr( $tag, 1 ),
						'depth' => $processor->get_current_depth(),
						'text'  => '',
					);
				}
			}
		}

		if ( '#text' === $token_type && ! empty( $stack ) ) {
			$index = count( $stack ) - 1;
			$stack[ $index ]['text'] .= $processor->get_modifiable_text();
		}

		$current_depth = $processor->get_current_depth();
		while ( ! empty( $stack ) ) {
			$index = count( $stack ) - 1;
			if ( $current_depth >= $stack[ $index ]['depth'] ) {
				break;
			}

			$entry   = array_pop( $stack );
			$toc[]   = array(
				'level' => $entry['level'],
				'text'  => $entry['text'],
			);
		}
	}

	while ( ! empty( $stack ) ) {
		$entry = array_pop( $stack );
		$toc[] = array(
			'level' => $entry['level'],
			'text'  => $entry['text'],
		);
	}

	return $toc;
}
