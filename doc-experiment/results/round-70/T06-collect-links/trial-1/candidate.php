<?php

function collect_links( string $html ): array {
	$processor = WP_HTML_Processor::create_fragment( $html );

	if ( null === $processor ) {
		return array();
	}

	$links         = array();
	$collecting    = false;
	$anchor_depth  = 0;
	$current_href  = '';
	$current_text  = '';

	while ( $processor->next_token() ) {
		if ( $collecting && '#text' === $processor->get_token_type() ) {
			$current_text .= $processor->get_modifiable_text();
		}

		if ( '#tag' === $processor->get_token_type() ) {
			if ( 'A' === $processor->get_tag() && ! $processor->is_tag_closer() ) {
				$href = $processor->get_attribute( 'href' );

				if ( is_string( $href ) ) {
					$collecting   = true;
					$anchor_depth = $processor->get_current_depth();
					$current_href = $href;
					$current_text = '';
				}
			}
		}

		if ( $collecting && $processor->get_current_depth() < $anchor_depth ) {
			$links[] = array(
				'href' => $current_href,
				'text' => $current_text,
			);

			$collecting   = false;
			$anchor_depth = 0;
			$current_href = '';
			$current_text = '';
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
