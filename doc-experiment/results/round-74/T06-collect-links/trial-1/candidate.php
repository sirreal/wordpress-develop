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
	$current = null;

	while ( $processor->next_token() ) {
		$token_type = $processor->get_token_type();

		if ( '#tag' === $token_type ) {
			$tag_name = $processor->get_tag();

			if ( 'A' === $tag_name ) {
				if ( ! $processor->is_tag_closer() ) {
					$href = $processor->get_attribute( 'href' );

					if ( is_string( $href ) ) {
						$current = array(
							'href' => $href,
							'text' => '',
							'depth' => $processor->get_current_depth(),
						);
					} else {
						$current = null;
					}
				} elseif ( null !== $current && $processor->get_current_depth() < $current['depth'] ) {
					$links[] = array(
						'href' => $current['href'],
						'text' => $current['text'],
					);
					$current = null;
				}
			}
		} elseif ( null !== $current && '#text' === $token_type ) {
			$current['text'] .= $processor->get_modifiable_text();
		}

		if ( null !== $current && $processor->get_current_depth() < $current['depth'] ) {
			$links[] = array(
				'href' => $current['href'],
				'text' => $current['text'],
			);
			$current = null;
		}
	}

	return $links;
}
