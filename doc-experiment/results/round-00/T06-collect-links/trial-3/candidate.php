<?php

function collect_links( string $html ): array {
	$processor = WP_HTML_Processor::create_fragment( $html );
	if ( null === $processor ) {
		return array();
	}

	$links        = array();
	$in_link      = false;
	$link_href    = null;
	$link_text    = '';
	$link_depth   = 0;

	while ( $processor->next_token() ) {
		$token_type = $processor->get_token_type();

		if ( '#tag' === $token_type ) {
			$tag_name = $processor->get_tag();

			if ( 'A' === $tag_name && ! $processor->is_tag_closer() ) {
				$href = $processor->get_attribute( 'href' );
				if ( null !== $href ) {
					$in_link    = true;
					$link_href  = $href;
					$link_text  = '';
					$link_depth = $processor->get_current_depth();
				}
			} elseif ( $in_link && 'A' === $tag_name && $processor->is_tag_closer() ) {
				// Closing an A tag at or below the depth where we opened it.
				if ( $processor->get_current_depth() < $link_depth ) {
					$links[] = array(
						'href' => $link_href,
						'text' => $link_text,
					);
					$in_link   = false;
					$link_href = null;
					$link_text = '';
				}
			}
		} elseif ( $in_link && '#text' === $token_type ) {
			$link_text .= $processor->get_modifiable_text();
		}
	}

	return $links;
}
