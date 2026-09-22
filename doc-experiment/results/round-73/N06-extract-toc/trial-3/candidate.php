<?php

function extract_toc( string $html ): array {
	$processor = WP_HTML_Processor::create_fragment( $html );
	if ( null === $processor ) {
		return array();
	}

	$toc = array();
	$current_level = null;
	$current_text = '';
	$current_depth = null;

	$flush_current = static function () use ( &$toc, &$current_level, &$current_text, &$current_depth ): void {
		if ( null === $current_level ) {
			return;
		}

		$toc[] = array(
			'level' => $current_level,
			'text'  => $current_text,
		);

		$current_level = null;
		$current_text  = '';
		$current_depth = null;
	};

	while ( $processor->next_token() ) {
		$token_type = $processor->get_token_type();

		if ( '#tag' === $token_type ) {
			$tag_name = $processor->get_token_name();
			if ( null === $tag_name ) {
				continue;
			}

			if ( ! $processor->is_tag_closer() && preg_match( '/^H([1-6])$/', $tag_name, $matches ) ) {
				$flush_current();
				$current_level = (int) $matches[1];
				$current_depth = $processor->get_current_depth();
				$current_text  = '';
				continue;
			}

			if ( null !== $current_level && $processor->is_tag_closer() ) {
				if ( $tag_name === 'H' . $current_level ) {
					$flush_current();
				}
			}

			continue;
		}

		if ( null !== $current_level && '#text' === $token_type && null !== $current_depth && $processor->get_current_depth() >= $current_depth ) {
			$current_text .= $processor->get_modifiable_text();
		}
	}

	$flush_current();

	return $toc;
}
