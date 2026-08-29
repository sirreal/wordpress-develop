<?php

function collect_links( string $html ): array {
	$processor = WP_HTML_Processor::create_fragment( $html );
	if ( null === $processor ) {
		return array();
	}

	$links = array();

	$current_link_text  = '';
	$current_link_depth = null;
	$collecting         = false;

	while ( $processor->next_token() ) {
		$depth = $processor->get_current_depth();

		if ( $collecting && $depth < $current_link_depth ) {
			$links[] = array(
				'href' => $current_href,
				'text' => $current_link_text,
			);

			$collecting         = false;
			$current_link_text  = '';
			$current_link_depth = null;
		}

		if ( '#tag' !== $processor->get_token_type() || $processor->is_tag_closer() ) {
			if ( $collecting && '#text' === $processor->get_token_type() ) {
				$current_link_text .= $processor->get_modifiable_text();
			}
			continue;
		}

		if ( 'A' !== $processor->get_tag() ) {
			if ( $collecting && '#text' === $processor->get_token_type() ) {
				$current_link_text .= $processor->get_modifiable_text();
			}
			continue;
		}

		$href = $processor->get_attribute( 'href' );
		if ( ! is_string( $href ) ) {
			if ( $collecting && '#text' === $processor->get_token_type() ) {
				$current_link_text .= $processor->get_modifiable_text();
			}
			continue;
		}

		$collecting         = true;
		$current_href       = $href;
		$current_link_text  = '';
		$current_link_depth = $depth;
	}

	if ( $collecting ) {
		$links[] = array(
			'href' => $current_href,
			'text' => $current_link_text,
		);
	}

	return $links;
}
