<?php

declare(strict_types=1);

function collect_links( string $html ): array {
	$processor = WP_HTML_Processor::create_fragment( $html );
	if ( null === $processor ) {
		return array();
	}

	$links = array();
	$current = null;
	$link_depth = null;
	$text = '';

	while ( $processor->next_token() ) {
		if ( null !== $current ) {
			if ( '#text' === $processor->get_token_type() ) {
				$text .= $processor->get_modifiable_text();
			}

			if ( $processor->get_current_depth() < $link_depth ) {
				$links[] = array(
					'href' => $current,
					'text' => $text,
				);
				$current = null;
				$link_depth = null;
				$text = '';
			}

			continue;
		}

		if ( '#tag' !== $processor->get_token_type() || $processor->is_tag_closer() ) {
			continue;
		}

		if ( 'A' !== $processor->get_tag() ) {
			continue;
		}

		$href = $processor->get_attribute( 'href' );
		if ( ! is_string( $href ) ) {
			continue;
		}

		$current = $href;
		$link_depth = $processor->get_current_depth();
		$text = '';
	}

	if ( null !== $current ) {
		$links[] = array(
			'href' => $current,
			'text' => $text,
		);
	}

	return $links;
}
