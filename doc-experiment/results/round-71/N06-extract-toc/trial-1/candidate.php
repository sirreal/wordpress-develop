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
		if ( '#tag' !== $processor->get_token_type() ) {
			if ( null !== $current_level && '#text' === $processor->get_token_type() ) {
				$current_text .= $processor->get_modifiable_text();
			}
			continue;
		}

		$tag_name = $processor->get_tag();
		if ( null === $tag_name || ! preg_match( '/^H[1-6]$/', $tag_name ) ) {
			continue;
		}

		$level = (int) substr( $tag_name, 1 );

		if ( ! $processor->is_tag_closer() ) {
			if ( null !== $current_level ) {
				$toc[] = array(
					'level' => $current_level,
					'text'  => $current_text,
				);
			}

			$current_level = $level;
			$current_depth = $processor->get_current_depth();
			$current_text  = '';
			continue;
		}

		if ( null !== $current_level && $level === $current_level ) {
			$toc[] = array(
				'level' => $current_level,
				'text'  => $current_text,
			);
			$current_level = null;
			$current_depth = null;
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
