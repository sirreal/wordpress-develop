<?php

function extract_toc( string $html ): array {
	$processor = WP_HTML_Processor::create_fragment( $html );
	if ( null === $processor ) {
		return array();
	}

	$toc = array();
	$current_level = null;
	$current_text = '';

	while ( $processor->next_token() ) {
		$token_type = $processor->get_token_type();

		if ( '#text' === $token_type ) {
			if ( null !== $current_level ) {
				$current_text .= $processor->get_modifiable_text();
			}
			continue;
		}

		if ( '#tag' !== $token_type ) {
			continue;
		}

		$tag_name = $processor->get_tag();
		if ( null === $tag_name ) {
			continue;
		}

		if ( $processor->is_tag_closer() ) {
			if ( null !== $current_level && 'H' . $current_level === $tag_name ) {
				$toc[] = array(
					'level' => $current_level,
					'text'  => $current_text,
				);
				$current_level = null;
				$current_text  = '';
			}
			continue;
		}

		if ( preg_match( '/^H([1-6])$/', $tag_name, $matches ) ) {
			if ( null !== $current_level ) {
				$toc[] = array(
					'level' => $current_level,
					'text'  => $current_text,
				);
			}

			$current_level = (int) $matches[1];
			$current_text  = '';
		}
	}

	if ( null !== $current_level ) {
		$toc[] = array(
			'level' => $current_level,
			'text'  => $current_text,
		);
	}

	return $toc;
}
