<?php

function extract_toc( string $html ): array {
	$processor = WP_HTML_Processor::create_fragment( $html );
	if ( null === $processor ) {
		return array();
	}

	$toc = array();
	$level = null;
	$text = '';

	while ( $processor->next_token() ) {
		$token_type = $processor->get_token_type();
		$token_name = $processor->get_token_name();

		if ( '#tag' === $token_type && null !== $token_name && preg_match( '/^H([1-6])$/', $token_name, $matches ) ) {
			if ( $processor->is_tag_closer() ) {
				if ( null !== $level ) {
					$toc[] = array(
						'level' => $level,
						'text'  => $text,
					);
				}

				$level = null;
				$text  = '';
				continue;
			}

			$level = (int) $matches[1];
			$text  = '';
			continue;
		}

		if ( null !== $level && '#text' === $token_type ) {
			$text .= $processor->get_modifiable_text();
		}
	}

	if ( null !== $level ) {
		$toc[] = array(
			'level' => $level,
			'text'  => $text,
		);
	}

	return $toc;
}
