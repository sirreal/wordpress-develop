<?php

function collect_links( string $html ): array {
	$processor = WP_HTML_Processor::create_fragment( $html );
	if ( null === $processor ) {
		return array();
	}

	$links = array();

	$current_text = null;
	$current_depth = null;
	$current_href = null;

	$flush_current = static function () use ( &$links, &$current_text, &$current_href, &$current_depth ): void {
		if ( null === $current_href ) {
			$current_text = null;
			$current_depth = null;
			return;
		}

		$links[] = array(
			'href' => $current_href,
			'text' => $current_text ?? '',
		);

		$current_text = null;
		$current_href = null;
		$current_depth = null;
	};

	while ( $processor->next_token() ) {
		if ( '#tag' !== $processor->get_token_type() ) {
			if ( null !== $current_href && '#text' === $processor->get_token_type() ) {
				$current_text .= $processor->get_modifiable_text();
			}
			continue;
		}

		$tag_name = $processor->get_tag();
		if ( 'A' !== $tag_name ) {
			if ( null !== $current_href && null !== $current_depth && $processor->get_current_depth() < $current_depth ) {
				$flush_current();
			}
			continue;
		}

		if ( $processor->is_tag_closer() ) {
			if ( null !== $current_href ) {
				$flush_current();
			}
			continue;
		}

		if ( null !== $current_href ) {
			$flush_current();
		}

		$href = $processor->get_attribute( 'href' );
		if ( ! is_string( $href ) ) {
			continue;
		}

		$current_href = $href;
		$current_text = '';
		$current_depth = $processor->get_current_depth();
	}

	if ( null !== $current_href ) {
		$flush_current();
	}

	return $links;
}
