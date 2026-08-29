<?php

declare(strict_types=1);

function collect_links( string $html ): array {
	if ( ! class_exists( 'WP_HTML_Processor' ) ) {
		return array();
	}

	$processor = WP_HTML_Processor::create_fragment( $html );
	if ( null === $processor ) {
		return array();
	}

	$links       = array();
	$current     = null;
	$currentDepth = null;

	while ( $processor->next_token() ) {
		if ( '#tag' === $processor->get_token_type() ) {
			if ( 'A' === $processor->get_tag() ) {
				if ( $processor->is_tag_closer() ) {
					if ( null !== $current ) {
						$links[] = array(
							'href' => $current['href'],
							'text' => $current['text'],
						);
					}

					$current      = null;
					$currentDepth = null;
				} else {
					$href = $processor->get_attribute( 'href' );
					if ( is_string( $href ) ) {
						$current = array(
							'href' => $href,
							'text' => '',
						);
						$currentDepth = $processor->get_current_depth();
					} else {
						$current      = null;
						$currentDepth = null;
					}
				}
			}

			continue;
		}

		if ( null !== $current && '#text' === $processor->get_token_type() ) {
			$current['text'] .= $processor->get_modifiable_text();
		}
	}

	if ( null !== $current ) {
		$links[] = array(
			'href' => $current['href'],
			'text' => $current['text'],
		);
	}

	return $links;
}
