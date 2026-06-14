<?php
function collect_links( string $html ): array {
	$processor = WP_HTML_Processor::create_fragment( $html );
	if ( ! $processor ) {
		return array();
	}

	$links = array();
	$current = null;

	while ( $processor->next_token() ) {
		if ( 'A' === $processor->get_token_name() ) {
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
			if ( is_string( $href ) ) {
				$current = array(
					'href' => $href,
					'text' => '',
				);
			} else {
				$current = null;
			}

			continue;
		}

		if ( null !== $current && '#text' === $processor->get_token_type() ) {
			$current['text'] .= $processor->get_modifiable_text();
		}
	}

	if ( null !== $current ) {
		$links[] = array(
			'href' => $current['href'],
			'text' => $current['text'],
		);
	}

	return $links;
}
