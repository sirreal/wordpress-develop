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
	$current_level = null;
	$current_text = '';

	while ( $processor->next_token() ) {
		$token_type = $processor->get_token_type();

		if ( '#tag' === $token_type ) {
			$tag_name = $processor->get_token_name();
			if ( null === $tag_name ) {
				continue;
			}

			if ( preg_match( '/^H([1-6])$/', $tag_name, $matches ) ) {
				$level = (int) $matches[1];

				if ( $processor->is_tag_closer() ) {
					if ( null !== $current_level && $current_level === $level ) {
						$toc[] = array(
							'level' => $current_level,
							'text'  => $current_text,
						);
						$current_level = null;
						$current_text = '';
					}
				} else {
					if ( null !== $current_level ) {
						$toc[] = array(
							'level' => $current_level,
							'text'  => $current_text,
						);
					}

					$current_level = $level;
					$current_text = '';
				}
			}

			continue;
		}

		if ( null !== $current_level && '#text' === $token_type ) {
			$current_text .= $processor->get_modifiable_text();
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
