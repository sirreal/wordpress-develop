<?php

function collect_links( string $html ): array {
	$processor = WP_HTML_Processor::create_fragment( $html );
	if ( null === $processor ) {
		return array();
	}

	$links = array();
	$current_link = null;
	$current_link_depth = null;

	while ( $processor->next_token() ) {
		$token_type = $processor->get_token_type();
		$token_name = $processor->get_token_name();

		if ( null !== $current_link && $processor->get_current_depth() < $current_link_depth ) {
			$links[] = $current_link;
			$current_link = null;
			$current_link_depth = null;
		}

		if ( '#tag' !== $token_type ) {
			if ( null !== $current_link && '#text' === $token_type ) {
				$current_link['text'] .= $processor->get_modifiable_text();
			}
			continue;
		}

		if ( $processor->is_tag_closer() ) {
			continue;
		}

		if ( 'A' !== $token_name || null !== $current_link ) {
			continue;
		}

		$href = $processor->get_attribute( 'href' );
		if ( ! is_string( $href ) ) {
			continue;
		}

		$current_link = array(
			'href' => $href,
			'text' => '',
		);
		$current_link_depth = $processor->get_current_depth();
	}

	if ( null !== $current_link ) {
		$links[] = $current_link;
	}

	return $links;
}
