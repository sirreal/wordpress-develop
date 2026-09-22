<?php

function collect_links( string $html ): array {
	if ( ! class_exists( 'WP_HTML_Processor' ) ) {
		return array();
	}

	$processor = WP_HTML_Processor::create_fragment( $html );
	if ( null === $processor ) {
		return array();
	}

	$links = array();
	$stack  = array();

	while ( $processor->next_token() ) {
		$token_type = $processor->get_token_type();

		if ( '#tag' === $token_type ) {
			if ( 'A' === $processor->get_tag() ) {
				if ( ! $processor->is_tag_closer() ) {
					$href = $processor->get_attribute( 'href' );
					if ( is_string( $href ) ) {
						$stack[] = array(
							'depth' => $processor->get_current_depth(),
							'href'  => $href,
							'text'   => '',
						);
					}
				} elseif ( ! empty( $stack ) ) {
					$current = array_pop( $stack );
					if ( null !== $current ) {
						$links[] = array(
							'href' => $current['href'],
							'text' => $current['text'],
						);
					}
				}
			}

			continue;
		}

		if ( '#text' === $token_type && ! empty( $stack ) ) {
			$text = $processor->get_modifiable_text();
			foreach ( $stack as $index => $entry ) {
				$stack[ $index ]['text'] .= $text;
			}
		}
	}

	return $links;
}
