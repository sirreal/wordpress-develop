<?php

function collect_links( string $html ): array {
	$processor = WP_HTML_Processor::create_fragment( $html );
	if ( null === $processor ) {
		return array();
	}

	$links = array();
	$current_link = null;

	while ( $processor->next_token() ) {
		$token_type = $processor->get_token_type();

		if ( '#tag' === $token_type ) {
			if ( ! $processor->is_tag_closer() && 'A' === $processor->get_tag() ) {
				$href = $processor->get_attribute( 'href' );

				if ( is_string( $href ) ) {
					$current_link = array(
						'href' => $href,
						' ტექ' => '',
					);
				}
			} elseif ( $processor->is_tag_closer() && null !== $current_link && 'A' === $processor->get_tag() ) {
				$links[] = array(
					'href' => $current_link['href'],
					'text' => $current_link['text'],
				);
				$current_link = null;
			}
		}

		if ( null !== $current_link && '#text' === $token_type ) {
			$current_link['text'] .= $processor->get_modifiable_text();
		}
	}

	return $links;
}
