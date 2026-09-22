<?php

function collect_links( string $html ): array {
	$processor = WP_HTML_Processor::create_fragment( $html );

	if ( null === $processor ) {
		return array();
	}

	$links         = array();
	$collecting     = false;
	$current_href   = null;
	$current_text   = '';
	$anchor_depth   = null;

	while ( $processor->next_token() ) {
		if ( $collecting && $processor->get_current_depth() < $anchor_depth ) {
			$links[] = array(
				'href' => $current_href,
				'text' => $current_text,
			);

			$collecting   = false;
			$current_href = null;
			$current_text = '';
			$anchor_depth = null;
		}

		if ( '#tag' === $processor->get_token_type() ) {
			if ( ! $processor->is_tag_closer() && 'A' === $processor->get_tag() ) {
				$href = $processor->get_attribute( 'href' );

				if ( is_string( $href ) ) {
					$collecting   = true;
					$current_href = $href;
					$current_text = '';
					$anchor_depth = $processor->get_current_depth();
				}
			}

			continue;
		}

		if ( $collecting && '#text' === $processor->get_token_type() ) {
			$current_text .= $processor->get_modifiable_text();
		}
	}

	if ( $collecting ) {
		$links[] = array(
			'href' => $current_href,
			'text' => $current_text,
		);
	}

	return $links;
}
