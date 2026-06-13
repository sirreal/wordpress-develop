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
	$current_level = null;
	$current_text = '';

	while ( $processor->next_token() ) {
		if ( '#tag' !== $processor->get_token_type() ) {
			if ( null !== $current_level && '#text' === $processor->get_token_type() ) {
				$current_text .= $processor->get_modifiable_text();
			}
			continue;
		}

		$tag_name = $processor->get_tag();
		if ( null === $tag_name ) {
			continue;
		}

		$is_heading = preg_match( '/^H([1-6])$/', $tag_name, $matches ) === 1;
		if ( ! $is_heading ) {
			if ( null !== $current_level && $processor->is_tag_closer() ) {
				// Ignore non-heading closers while a heading is open.
			}
			continue;
		}

		$level = (int) $matches[1];

		if ( $processor->is_tag_closer() ) {
			if ( null !== $current_level && $current_level === $level ) {
				$toc[] = array(
					'level' => $current_level,
					'text'  => $current_text,
				);
				$current_level = null;
				$current_text  = '';
			}
			continue;
		}

		if ( null !== $current_level ) {
			$toc[] = array(
				'level' => $current_level,
				'text'  => $current_text,
			);
		}

		$current_level = $level;
		$current_text  = '';
	}

	if ( null !== $current_level ) {
		$toc[] = array(
			'level' => $current_level,
			'text'  => $current_text,
		);
	}

	return $toc;
}
