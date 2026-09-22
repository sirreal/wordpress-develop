<?php

declare(strict_types=1);

function collect_links( string $html ): array {
	$processor = WP_HTML_Processor::create_fragment( $html );
	if ( null === $processor ) {
		return array();
	}

	$links = array();
	$current_link = null;

	while ( $processor->next_token() ) {
		if ( '#tag' !== $processor->get_token_type() ) {
			if ( null !== $current_link && '#text' === $processor->get_token_type() ) {
				$current_link['text'] .= $processor->get_modifiable_text();
			}
			continue;
		}

		if ( 'A' !== $processor->get_tag() ) {
			if ( null !== $current_link && '#text' === $processor->get_token_type() ) {
				$current_link['text'] .= $processor->get_modifiable_text();
			}
			continue;
		}

		if ( $processor->is_tag_closer() ) {
			if ( null !== $current_link ) {
				$links[] = array(
					'href' => $current_link['href'],
					'text' => $current_link['text'],
				);
				$current_link = null;
			}

			continue;
		}

		$href = $processor->get_attribute( 'href' );
		if ( ! is_string( $href ) ) {
			$current_link = null;
			continue;
		}

		$current_link = array(
			'href' => $href,
			'text' => '',
		);
	}

	return $links;
}
