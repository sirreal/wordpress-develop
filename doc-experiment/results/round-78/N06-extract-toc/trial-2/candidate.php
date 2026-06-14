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
	$current_depth  = null;
	$current_text   = '';

	$flush_current = static function () use ( &$toc, &$current_level, &$current_text, &$current_depth ): void {
		if ( null === $current_level ) {
			return;
		}

		$toc[] = array(
			'level' => $current_level,
			'text'  => $current_text,
		);

		$current_level = null;
		$current_depth = null;
		$current_text  = '';
	};

	while ( $processor->next_token() ) {
		$token_type = $processor->get_token_type();
		$token_name = $processor->get_token_name();

		$is_heading = in_array( $token_name, array( 'H1', 'H2', 'H3', 'H4', 'H5', 'H6' ), true );

		if ( $is_heading && ! $processor->is_tag_closer() ) {
			if ( null !== $current_level && $processor->get_current_depth() < $current_depth ) {
				$flush_current();
			}

			$current_level = (int) substr( $token_name, 1 );
			$current_depth = $processor->get_current_depth();
			$current_text  = '';
			continue;
		}

		if ( null !== $current_level ) {
			if ( $processor->get_current_depth() < $current_depth ) {
				$flush_current();
			}
		}

		if ( null === $current_level ) {
			continue;
		}

		if ( '#text' === $token_type ) {
			$current_text .= $processor->get_modifiable_text();
		}
	}

	$flush_current();

	return $toc;
}
