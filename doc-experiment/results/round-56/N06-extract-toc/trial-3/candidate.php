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
	$current_depth = null;
	$current_text   = '';

	$heading_tags = array(
		'H1' => 1,
		'H2' => 2,
		'H3' => 3,
		'H4' => 4,
		'H5' => 5,
		'H6' => 6,
	);

	while ( $processor->next_token() ) {
		$token_type = $processor->get_token_type();
		$token_name = $processor->get_token_name();
		$depth      = $processor->get_current_depth();

		if ( null !== $current_depth && $depth < $current_depth ) {
			$toc[] = array(
				'level' => $current_level,
				'text'  => $current_text,
			);

			$current_level = null;
			$current_depth = null;
			$current_text   = '';
		}

		if ( '#tag' !== $token_type || null === $token_name ) {
			if ( null !== $current_level && '#text' === $token_type ) {
				$current_text .= $processor->get_modifiable_text();
			}
			continue;
		}

		if ( isset( $heading_tags[ $token_name ] ) && ! $processor->is_tag_closer() ) {
			if ( null !== $current_depth ) {
				$toc[] = array(
					'level' => $current_level,
					'text'  => $current_text,
				);
			}

			$current_level = $heading_tags[ $token_name ];
			$current_depth = $depth;
			$current_text   = '';
		}

		if ( null !== $current_level && '#text' === $token_type ) {
			$current_text .= $processor->get_modifiable_text();
		}
	}

	if ( null !== $current_depth ) {
		$toc[] = array(
			'level' => $current_level,
			'text'  => $current_text,
		);
	}

	return $toc;
}
