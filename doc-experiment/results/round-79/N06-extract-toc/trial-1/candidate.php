<?php

function extract_toc( string $html ): array {
	if ( ! class_exists( 'WP_HTML_Processor' ) ) {
		return array();
	}

	$processor = WP_HTML_Processor::create_fragment( $html );
	if ( null === $processor ) {
		return array();
	}

	$toc = array();
	$current = null;

	while ( $processor->next_token() ) {
		if ( null !== $current && $processor->get_current_depth() < $current['depth'] ) {
			$toc[] = array(
				'level' => $current['level'],
				'text'  => $current['text'],
			);
			$current = null;
		}

		if ( '#tag' !== $processor->get_token_type() || $processor->is_tag_closer() ) {
			if ( null !== $current && '#text' === $processor->get_token_type() ) {
				$current['text'] .= $processor->get_modifiable_text();
			}
			continue;
		}

		$tag = $processor->get_tag();
		if ( null === $tag || ! preg_match( '/^H([1-6])$/', $tag, $matches ) ) {
			continue;
		}

		if ( null !== $current ) {
			$toc[] = array(
				'level' => $current['level'],
				'text'  => $current['text'],
			);
		}

		$current = array(
			'level' => (int) $matches[1],
			'text'  => '',
			'depth' => $processor->get_current_depth(),
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
