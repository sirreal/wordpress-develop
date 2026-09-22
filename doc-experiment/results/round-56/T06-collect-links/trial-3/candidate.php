<?php

declare(strict_types=1);

function collect_links( string $html ): array {
	$processor = WP_HTML_Processor::create_fragment( $html );
	if ( null === $processor ) {
		return array();
	}

	$links = array();

	while ( $processor->next_tag( array( 'tag_name' => 'a' ) ) ) {
		$href = $processor->get_attribute( 'href' );
		if ( ! is_string( $href ) ) {
			continue;
		}

		$anchor_depth = $processor->get_current_depth();
		$text         = '';

		while ( $processor->next_token() && $processor->get_current_depth() >= $anchor_depth ) {
			if ( '#text' === $processor->get_token_type() ) {
				$text .= $processor->get_modifiable_text();
			}
		}

		$links[] = array(
			'href' => $href,
			'text' => $text,
		);

		if ( ! $processor->next_token() ) {
			break;
		}
	}

	return $links;
}
