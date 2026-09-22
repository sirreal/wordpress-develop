<?php

function extract_toc( string $html ): array {
	if ( ! class_exists( 'WP_HTML_Processor' ) ) {
		return array();
	}

	$processor = WP_HTML_Processor::create_fragment( $html );
	if ( null === $processor ) {
		return array();
	}

	$toc          = array();
	$active_level = null;
	$active_depth = null;
	$active_tag   = null;
	$buffer       = '';

	$flush_heading = static function () use ( &$toc, &$active_level, &$buffer, &$active_depth, &$active_tag ) : void {
		if ( null === $active_level ) {
			return;
		}

		$toc[] = array(
			'level' => $active_level,
			'text'  => $buffer,
		);

		$active_level = null;
		$active_depth = null;
		$active_tag   = null;
		$buffer       = '';
	};

	while ( $processor->next_token() ) {
		$token_type = $processor->get_token_type();

		if ( null !== $active_level ) {
			if ( '#tag' === $token_type ) {
				$current_tag = $processor->get_tag();
				if ( $current_tag === $active_tag && $processor->is_tag_closer() ) {
					$flush_heading();
					continue;
				}

				if ( $processor->get_current_depth() < $active_depth ) {
					$flush_heading();
				}
			} elseif ( $processor->get_current_depth() < $active_depth ) {
				$flush_heading();
			}
		}

		if ( '#tag' !== $token_type || $processor->is_tag_closer() ) {
			if ( null !== $active_level && '#text' === $token_type && $processor->get_current_depth() >= $active_depth ) {
				$buffer .= $processor->get_modifiable_text();
			}
			continue;
		}

		$tag = $processor->get_tag();
		if ( null === $tag || ! preg_match( '/^H([1-6])$/', $tag, $matches ) ) {
			continue;
		}

		$active_level = (int) $matches[1];
		$active_depth = $processor->get_current_depth();
		$active_tag   = $tag;
		$buffer       = '';
	}

	$flush_heading();

	return $toc;
}
