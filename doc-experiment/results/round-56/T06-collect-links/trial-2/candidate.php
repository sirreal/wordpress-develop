<?php

function collect_links( string $html ): array {
	$processor = WP_HTML_Processor::create_fragment( $html );

	if ( null === $processor ) {
		return array();
	}

	$links = array();
	$link  = null;
	$depth = null;

	while ( $processor->next_token() ) {
		if ( 'A' === $processor->get_tag() ) {
			if ( $processor->is_tag_closer() ) {
				if ( null !== $link ) {
					$links[] = $link;
					$link     = null;
					$depth    = null;
				}
				continue;
			}

			$href = $processor->get_attribute( 'href' );
			if ( ! is_string( $href ) ) {
				$link  = null;
				$depth = null;
				continue;
			}

			$link = array(
				'href' => $href,
				'text' => '',
			);
			$depth = $processor->get_current_depth();
			continue;
		}

		if ( null === $link ) {
			continue;
		}

		if ( $processor->get_current_depth() < $depth ) {
			$links[] = $link;
			$link     = null;
			$depth    = null;
			continue;
		}

		if ( '#text' === $processor->get_token_type() ) {
			$link['text'] .= $processor->get_modifiable_text();
		}
	}

	if ( null !== $link ) {
		$links[] = $link;
	}

	return $links;
}
