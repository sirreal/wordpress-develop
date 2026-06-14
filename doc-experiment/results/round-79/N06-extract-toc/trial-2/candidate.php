<?php

declare( strict_types=1 );

function extract_toc( string $html ): array {
	$processor = WP_HTML_Processor::create_fragment( $html );
	if ( null === $processor ) {
		return array();
	}

	$toc = array();

	$active_heading_level = null;
	$active_heading_depth = null;
	$active_heading_text = '';

	$flush_heading = static function () use ( &$toc, &$active_heading_level, &$active_heading_text, &$active_heading_depth ): void {
		if ( null === $active_heading_level ) {
			return;
		}

		$toc[] = array(
			'level' => $active_heading_level,
			'text'  => $active_heading_text,
		);

		$active_heading_level = null;
		$active_heading_depth = null;
		$active_heading_text  = '';
	};

	while ( $processor->next_token() ) {
		$token_type = $processor->get_token_type();
		$token_name = $processor->get_token_name();
		$depth      = $processor->get_current_depth();

		if ( null !== $active_heading_level && $depth < $active_heading_depth ) {
			$flush_heading();
		}

		if ( '#text' === $token_type ) {
			if ( null !== $active_heading_level ) {
				$active_heading_text .= $processor->get_modifiable_text();
			}
			continue;
		}

		if ( null === $token_name || 'H1' > $token_name || 'H6' < $token_name ) {
			continue;
		}

		if ( $processor->is_tag_closer() ) {
			if ( null !== $active_heading_level && strtoupper( 'H' . $active_heading_level ) === $token_name ) {
				$flush_heading();
			}
			continue;
		}

		$flush_heading();
		$active_heading_level = (int) substr( $token_name, 1, 1 );
		$active_heading_depth = $depth;
		$active_heading_text  = $processor->get_modifiable_text();
	}

	$flush_heading();

	return $toc;
}
