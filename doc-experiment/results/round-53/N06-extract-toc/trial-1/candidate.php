<?php

if ( ! function_exists( 'extract_toc' ) ) {
	/**
	 * Extract headings from an HTML fragment as a table of contents.
	 *
	 * @param string $html HTML fragment found inside <body>.
	 * @return array<int, array{level:int, text:string}>
	 */
	function extract_toc( string $html ): array {
		if ( ! class_exists( 'WP_HTML_Processor' ) ) {
			return array();
		}

		$processor = WP_HTML_Processor::create_fragment( $html );
		if ( null === $processor ) {
			return array();
		}

		$toc = array();
		$current_heading_level = null;
		$current_heading_depth = null;
		$current_heading_text  = '';

		while ( $processor->next_token() ) {
			$token_type = $processor->get_token_type();

			if ( null !== $current_heading_level ) {
				$current_depth = $processor->get_current_depth();

				if ( '#text' === $token_type ) {
					$current_heading_text .= $processor->get_modifiable_text();
					continue;
				}

				if ( $current_depth >= $current_heading_depth ) {
					continue;
				}

				$toc[] = array(
					'level' => $current_heading_level,
					'text'  => $current_heading_text,
				);

				$current_heading_level = null;
				$current_heading_depth = null;
				$current_heading_text  = '';
			}

			if ( '#tag' !== $token_type || $processor->is_tag_closer() ) {
				continue;
			}

			$tag_name = $processor->get_tag();
			if ( null === $tag_name || 2 !== strlen( $tag_name ) || 'H' !== $tag_name[0] ) {
				continue;
			}

			$level_char = $tag_name[1];
			if ( $level_char < '1' || $level_char > '6' ) {
				continue;
			}

			$current_heading_level = (int) $level_char;
			$current_heading_depth = $processor->get_current_depth();
			$current_heading_text  = '';
		}

		if ( null !== $current_heading_level ) {
			$toc[] = array(
				'level' => $current_heading_level,
				'text'  => $current_heading_text,
			);
		}

		return $toc;
	}
}
