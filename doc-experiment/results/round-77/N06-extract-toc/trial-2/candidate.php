<?php

declare(strict_types=1);

function extract_toc( string $html ): array {
	if ( ! class_exists( 'WP_HTML_Processor' ) ) {
		return array();
	}

	$processor = WP_HTML_Processor::create_fragment( $html );
	if ( null === $processor ) {
		return array();
	}

	$toc = array();

	while ( $processor->next_token() ) {
		if ( '#tag' !== $processor->get_token_type() ) {
			continue;
		}

		if ( $processor->is_tag_closer() ) {
			continue;
		}

		$tag_name = $processor->get_tag();
		if ( null === $tag_name || ! preg_match( '/^H[1-6]$/', $tag_name ) ) {
			continue;
		}

		$level = (int) substr( $tag_name, 1 );
		$depth = $processor->get_current_depth();
		$text  = '';

		while ( $processor->next_token() && $processor->get_current_depth() >= $depth ) {
			if ( '#text' === $processor->get_token_type() ) {
				$text .= $processor->get_modifiable_text();
			}
		}

		$toc[] = array(
			'level' => $level,
			'text'  => $text,
		);
	}

	return $toc;
}
