<?php

declare(strict_types=1);

function collect_links( string $html ): array {
	$processor = WP_HTML_Processor::create_fragment( $html );
	if ( null === $processor ) {
		return array();
	}

	$links = array();
	$current = null;

	while ( $processor->next_token() ) {
		if ( '#tag' !== $processor->get_token_type() || 'A' !== $processor->get_tag() ) {
			if ( null !== $current && '#text' === $processor->get_token_type() ) {
				$current['text'] .= $processor->get_modifiable_text();
			}
			continue;
		}

		if ( $processor->is_tag_closer() ) {
			if ( null !== $current ) {
				$links[] = array(
					'href' => $current['href'],
					'text' => $current['text'],
				);
				$current = null;
			}
			continue;
		}

		$href = $processor->get_attribute( 'href' );
		if ( ! is_string( $href ) ) {
			$current = null;
			continue;
		}

		$current = array(
			'href' => $href,
			'text' => '',
		);
	}

	return $links;
}
