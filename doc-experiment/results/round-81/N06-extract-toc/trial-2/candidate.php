<?php

function extract_toc( string $html ): array {
	$processor = WP_HTML_Processor::create_fragment( $html );
	if ( null === $processor ) {
		return array();
	}

	$toc = array();
	$current = null;

	while ( $processor->next_token() ) {
		if ( null !== $current ) {
			if ( $processor->get_current_depth() < $current['depth'] ) {
				$toc[] = array(
					'level' => $current['level'],
					'text'  => $current['text'],
				);
				$current = null;
			}
		}

		if ( '#tag' !== $processor->get_token_type() || $processor->is_tag_closer() ) {
			continue;
		}

		$tag = $processor->get_tag();
		if ( null === $tag || ! preg_match( '/^H([1-6])$/', $tag, $matches ) ) {
			continue;
		}

		$current = array(
			'level' => (int) $matches[1],
			'depth' => $processor->get_current_depth(),
			'text'  => '',
		);
	}

	if ( null !== $current ) {
		$toc[] = array(
			'level' => $current['level'],
			'text'  => $current['text'],
		);
	}

	return $toc;
}
