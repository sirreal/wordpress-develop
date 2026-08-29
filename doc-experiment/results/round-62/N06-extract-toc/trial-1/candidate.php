<?php

function extract_toc( string $html ): array {
	$processor = WP_HTML_Processor::create_fragment( $html );
	if ( ! $processor ) {
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

		if ( null !== $current_level && $processor->get_current_depth() < $current_depth ) {
			$flush_current();
		}

		if ( '#tag' !== $token_type ) {
			if ( null !== $current_level && '#text' === $token_type ) {
				$current_text .= $processor->get_modifiable_text();
			}
			continue;
		}

		$tag_name = $processor->get_tag();
		if ( null === $tag_name ) {
			continue;
		}

		if ( ! preg_match( '/^H([1-6])$/', $tag_name, $matches ) ) {
			continue;
		}

		if ( $processor->is_tag_closer() ) {
			if ( null !== $current_level && (int) $matches[1] === $current_level ) {
				$flush_current();
			}
			continue;
		}

		$flush_current();
		$current_level = (int) $matches[1];
		$current_depth = $processor->get_current_depth();
		$current_text  = '';
	}

	$flush_current();

	return $toc;
}
