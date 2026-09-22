<?php
/**
 * Collect links from an HTML fragment.
 *
 * @param string $html HTML fragment found inside <body>.
 * @return array<int, array{href:string,text:string}>
 */
function collect_links( string $html ): array {
	if ( ! class_exists( 'WP_HTML_Processor' ) ) {
		return array();
	}

	$processor = WP_HTML_Processor::create_fragment( $html );
	if ( null === $processor ) {
		return array();
	}

	$links = array();
	$stack = array();

	while ( $processor->next_token() ) {
		$token_type = $processor->get_token_type();

		if ( '#tag' === $token_type && 'A' === $processor->get_tag() ) {
			if ( $processor->is_tag_closer() ) {
				$context = array_pop( $stack );
				if ( null !== $context ) {
					$links[] = array(
						'href' => $context['href'],
						'text' => $context['text'],
					);
				}

				continue;
			}

			$href = $processor->get_attribute( 'href' );
			if ( is_string( $href ) ) {
				$stack[] = array(
					'href' => $href,
					'text' => '',
				);
			}

			continue;
		}

		if ( '#text' === $token_type && ! empty( $stack ) ) {
			$index = count( $stack ) - 1;
			$stack[ $index ]['text'] .= $processor->get_modifiable_text();
		}
	}

	while ( ! empty( $stack ) ) {
		$context = array_pop( $stack );
		$links[] = array(
			'href' => $context['href'],
			'text' => $context['text'],
		);
	}

	return $links;
}
