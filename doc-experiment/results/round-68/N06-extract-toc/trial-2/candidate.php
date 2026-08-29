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
	$current_heading = null;
	$current_level = null;
	$current_text = '';

	while ( $processor->next_token() ) {
		$token_type = $processor->get_token_type();

		if ( null !== $current_heading && $processor->get_current_depth() < $current_level ) {
			$toc[] = array(
				'level' => $current_level,
				'text'  => $current_text,
			);

			$current_heading = null;
			$current_level = null;
			$current_text = '';
		}

		if ( '#tag' !== $token_type ) {
			if ( null !== $current_heading && '#text' === $token_type ) {
				$current_text .= $processor->get_modifiable_text();
			}
			continue;
		}

		if ( $processor->is_tag_closer() ) {
			continue;
		}

		$tag_name = $processor->get_tag();
		if ( null === $tag_name || ! preg_match( '/^H([1-6])$/', $tag_name, $matches ) ) {
			continue;
		}

		if ( null !== $current_heading ) {
			$toc[] = array(
				'level' => $current_level,
				'text'  => $current_text,
			);
		}

		$current_heading = $tag_name;
		$current_level = (int) $matches[1];
		$current_text = '';
	}

	if ( null !== $current_heading ) {
		$toc[] = array(
			'level' => $current_level,
			'text'  => $current_text,
		);
	}

	return $toc;
}
