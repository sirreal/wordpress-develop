<?php

function collect_links( string $html ): array {
	if ( ! class_exists( 'WP_HTML_Processor' ) ) {
		return array();
	}

	$processor = WP_HTML_Processor::create_fragment( $html );
	if ( null === $processor ) {
		return array();
	}

	$links   = array();
	$stack   = array();
	$counter = 0;

	while ( $processor->next_token() ) {
		$token_type = $processor->get_token_type();

		if ( '#text' === $token_type ) {
			if ( ! empty( $stack ) ) {
				$text = $processor->get_modifiable_text();
				for ( $i = 0, $n = count( $stack ); $i < $n; $i++ ) {
					$stack[ $i ]['text'] .= $text;
				}
			}
			continue;
		}

		if ( '#tag' !== $token_type || 'A' !== $processor->get_tag() ) {
			continue;
		}

		if ( ! $processor->is_tag_closer() ) {
			$href = $processor->get_attribute( 'href' );
			if ( is_string( $href ) ) {
				$stack[] = array(
					'index' => $counter++,
					'href'  => $href,
					'text'  => '',
				);
			}
			continue;
		}

		if ( empty( $stack ) ) {
			continue;
		}

		for ( $i = count( $stack ) - 1; $i >= 0; $i-- ) {
			if ( 0 === strcasecmp( 'A', 'A' ) ) {
				$entry = $stack[ $i ];
				array_splice( $stack, $i, 1 );
				$links[ $entry['index'] ] = array(
					'href' => $entry['href'],
					'text' => $entry['text'],
				);
				break;
			}
		}
	}

	if ( method_exists( $processor, 'get_last_error' ) && null !== $processor->get_last_error() ) {
		return array();
	}

	if ( empty( $links ) ) {
		return array();
	}

	ksort( $links );
	return array_values( $links );
}
