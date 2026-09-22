<?php

declare(strict_types=1);

function extract_toc( string $html ): array {
	$processor = WP_HTML_Processor::create_fragment( $html );
	if ( null === $processor ) {
		return array();
	}

	$toc = array();

	$current_level = null;
	$current_depth = null;
	$current_text  = '';

	while ( $processor->next_token() ) {
		if ( null !== $current_level ) {
			if ( $processor->get_current_depth() < $current_depth ) {
				$toc[] = array(
					'level' => $current_level,
					'text'  => $current_text,
				);

				$current_level = null;
				$current_depth = null;
				$current_text  = '';
				continue;
			}

			if ( '#text' === $processor->get_token_type() ) {
				$current_text .= $processor->get_modifiable_text();
			}
		}

		if ( '#tag' !== $processor->get_token_type() || $processor->is_tag_closer() ) {
			continue;
		}

		$tag = $processor->get_tag();
		if ( null === $tag || 1 !== preg_match( '/^H([1-6])$/', $tag, $matches ) ) {
			continue;
		}

		$current_level = (int) $matches[1];
		$current_depth = $processor->get_current_depth();
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
