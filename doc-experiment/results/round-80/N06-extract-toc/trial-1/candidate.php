<?php

declare(strict_types=1);

function extract_toc( string $html ): array {
	$processor = WP_HTML_Processor::create_fragment( $html );
	if ( null === $processor ) {
		return array();
	}

	$toc = array();

	$current_heading = null;
	$current_level   = null;
	$current_text    = '';

	while ( $processor->next_token() ) {
		$token_type = $processor->get_token_type();

		if ( '#tag' === $token_type ) {
			$tag_name = $processor->get_tag();
			if ( null === $tag_name ) {
				continue;
			}

			if ( $processor->is_tag_closer() ) {
				if ( null !== $current_heading && $tag_name === $current_heading ) {
					$toc[] = array(
						'level' => $current_level,
						'text'  => $current_text,
					);

					$current_heading = null;
					$current_level   = null;
					$current_text    = '';
				}

				continue;
			}

			if ( preg_match( '/^H([1-6])$/', $tag_name, $matches ) ) {
				$current_heading = $tag_name;
				$current_level   = (int) $matches[1];
				$current_text    = '';
			}

			continue;
		}

		if ( null !== $current_heading && '#text' === $token_type ) {
			$current_text .= $processor->get_modifiable_text();
		}
	}

	return $toc;
}
