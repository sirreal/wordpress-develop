<?php
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
	$current_text  = '';

	$flush_current = static function () use ( &$toc, &$current_level, &$current_text ): void {
		if ( null === $current_level ) {
			return;
		}

		$toc[] = array(
			'level' => $current_level,
			'text'  => $current_text,
		);

		$current_level = null;
		$current_text  = '';
	};

	while ( $processor->next_token() ) {
		if ( '#tag' === $processor->get_token_type() ) {
			$tag_name = $processor->get_tag();
			if ( null === $tag_name ) {
				continue;
			}

			if ( $processor->is_tag_closer() ) {
				if ( null !== $current_level && 'H' . $current_level === $tag_name ) {
					$flush_current();
				}
				continue;
			}

			if ( preg_match( '/^H([1-6])$/', $tag_name, $matches ) ) {
				if ( null !== $current_level ) {
					$flush_current();
				}

				$current_level = (int) $matches[1];
				$current_text  = '';
			}

			continue;
		}

		if ( null !== $current_level && '#text' === $processor->get_token_type() ) {
			$current_text .= $processor->get_modifiable_text();
		}
	}

	if ( null !== $current_level ) {
		$flush_current();
	}

	return $toc;
}
