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

	$flush_current = static function () use ( &$toc, &$current ): void {
		if ( null === $current ) {
			return;
		}

		$toc[] = array(
			'level' => $current['level'],
			'text'  => $current['text'],
		);
		$current = null;
	};

	while ( $processor->next_token() ) {
		$token_type = $processor->get_token_type();
		if ( '#tag' !== $token_type ) {
			if ( null !== $current && '#text' === $token_type ) {
				$current['text'] .= $processor->get_modifiable_text();
			}
			continue;
		}

		$tag_name = $processor->get_tag();
		if ( null === $tag_name ) {
			continue;
		}

		$is_heading = in_array( $tag_name, array( 'H1', 'H2', 'H3', 'H4', 'H5', 'H6' ), true );

		if ( null !== $current ) {
			if ( $processor->is_tag_closer() && ( 'H' . $current['level'] === $tag_name ) ) {
				$flush_current();
				continue;
			}

			if ( $is_heading && ! $processor->is_tag_closer() ) {
				$flush_current();
			}
		}

		if ( $is_heading && ! $processor->is_tag_closer() ) {
			$current = array(
				'level' => (int) substr( $tag_name, 1, 1 ),
				'text'  => '',
			);
		}
	}

	$flush_current();

	return $toc;
}
