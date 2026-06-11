<?php

function collect_links( string $html ): array {
	$processor = WP_HTML_Processor::create_fragment( $html );
	if ( null === $processor ) {
		return array();
	}

	$links = array();

	while ( $processor->next_tag( array( 'tag_name' => 'a' ) ) ) {
		$href = $processor->get_attribute( 'href' );
		if ( null === $href ) {
			continue;
		}

		$link_depth = $processor->get_current_depth();
		$text = '';
		
		while ( $processor->next_token() ) {
			$current_depth = $processor->get_current_depth();
			if ( $current_depth < $link_depth ) {
				break;
			}
			if ( '#text' === $processor->get_token_type() ) {
				$text .= $processor->get_modifiable_text();
			}
		}

		$links[] = array(
			'href' => $href,
			'text' => $text,
		);
	}

	return $links;
}
