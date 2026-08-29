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

	$heading_depth = null;
	$heading_level = null;
	$heading_text  = '';

	$flush_heading = static function () use ( &$toc, &$heading_depth, &$heading_level, &$heading_text ): void {
		if ( null === $heading_level ) {
			return;
		}

		$toc[] = array(
			'level' => $heading_level,
			'text'  => $heading_text,
		);

		$heading_depth = null;
		$heading_level = null;
		$heading_text  = '';
	};

	while ( $processor->next_token() ) {
		$token_type = $processor->get_token_type();
		$token_name = $processor->get_token_name();

		if ( '#tag' === $token_type ) {
			if ( null !== $heading_level && null !== $heading_depth && $processor->get_current_depth() < $heading_depth ) {
				$flush_heading();
			}

			if ( null !== $token_name && preg_match( '/^H([1-6])$/', $token_name, $matches ) ) {
				if ( $processor->is_tag_closer() ) {
					if ( (int) $matches[1] === $heading_level ) {
						$flush_heading();
					}
					continue;
				}

				if ( null !== $heading_level ) {
					$flush_heading();
				}

				$heading_level = (int) $matches[1];
				$heading_depth = $processor->get_current_depth();
				$heading_text  = '';
				continue;
			}
		}

		if ( null === $heading_level || null === $heading_depth ) {
			continue;
		}

		if ( $processor->get_current_depth() < $heading_depth ) {
			$flush_heading();
			continue;
		}

		if ( '#text' === $token_type ) {
			$heading_text .= $processor->get_modifiable_text();
		}
	}

	$flush_heading();

	return $toc;
}
