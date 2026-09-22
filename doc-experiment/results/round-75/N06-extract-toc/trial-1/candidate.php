<?php

function extract_toc( string $html ): array {
	$processor = WP_HTML_Processor::create_fragment( $html );
	if ( null === $processor ) {
		return array();
	}

	$toc = array();
	$current_level = null;
	$current_text = '';

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
		if ( '#tag' !== $processor->get_token_type() ) {
			if ( null !== $current_level && '#text' === $processor->get_token_type() ) {
				$current_text .= $processor->get_modifiable_text();
			}
			continue;
		}

		$tag = $processor->get_tag();
		if ( null === $tag ) {
			continue;
		}

		if ( preg_match( '/^H([1-6])$/', $tag, $matches ) ) {
			if ( ! $processor->is_tag_closer() ) {
				$flush_current();
				$current_level = (int) $matches[1];
				$current_text  = '';
			} elseif ( null !== $current_level ) {
				$flush_current();
			}
		}
	}

	$flush_current();

	return $toc;
}
