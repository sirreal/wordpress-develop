<?php

if ( ! function_exists( 'extract_toc' ) ) {
	/**
	 * Extract a table of contents from an HTML fragment.
	 *
	 * @param string $html HTML fragment.
	 * @return array<int, array{level:int, text:string}>
	 */
	function extract_toc( string $html ): array {
		$processor = WP_HTML_Processor::create_fragment( $html );
		if ( null === $processor ) {
			return array();
		}

		$toc           = array();
		$current_level = null;
		$current_depth = null;
		$current_text  = '';

		$flush_current = static function () use ( &$toc, &$current_level, &$current_depth, &$current_text ): void {
			if ( null === $current_level ) {
				return;
			}

			$toc[] = array(
				'level' => $current_level,
				'text'  => $current_text,
			);

			$current_level = null;
			$current_depth = null;
			$current_text  = '';
		};

		while ( $processor->next_token() ) {
			$token_type = $processor->get_token_type();

			if ( '#tag' === $token_type ) {
				$tag_name = $processor->get_tag();
				if ( null === $tag_name ) {
					continue;
				}

				if ( ! $processor->is_tag_closer() && in_array( $tag_name, array( 'H1', 'H2', 'H3', 'H4', 'H5', 'H6' ), true ) ) {
					$flush_current();
					$current_level = (int) substr( $tag_name, 1 );
					$current_depth = $processor->get_current_depth();
					$current_text  = '';
					continue;
				}

				if ( null !== $current_level && $processor->is_tag_closer() && $processor->get_current_depth() < $current_depth ) {
					$flush_current();
				}

				continue;
			}

			if ( null !== $current_level && '#text' === $token_type && $processor->get_current_depth() >= $current_depth ) {
				$current_text .= $processor->get_modifiable_text();
			}
		}

		$flush_current();

		return $toc;
	}
}
