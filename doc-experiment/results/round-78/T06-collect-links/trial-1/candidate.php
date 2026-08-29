<?php

function collect_links( string $html ): array {
	$processor = WP_HTML_Processor::create_fragment( $html );
	if ( null === $processor ) {
		return array();
	}

	$links = array();
	$collecting = false;
	$link_depth = null;
	$current_href = null;
	$current_text = '';

	while ( $processor->next_token() ) {
		if ( ! $collecting ) {
			if ( 'A' !== $processor->get_token_name() || $processor->is_tag_closer() ) {
				continue;
			}

			$href = $processor->get_attribute( 'href' );
			if ( ! is_string( $href ) ) {
				continue;
			}

			$collecting = true;
			$link_depth = $processor->get_current_depth();
			$current_href = $href;
			$current_text = '';
			continue;
		}

		if ( '#text' === $processor->get_token_type() ) {
			$current_text .= $processor->get_modifiable_text();
		}

		if ( 'A' === $processor->get_token_name() && $processor->is_tag_closer() ) {
			$links[] = array(
				'href' => $current_href,
				'text' => $current_text,
			);

			$collecting = false;
			$link_depth = null;
			$current_href = null;
			$current_text = '';
			continue;
		}

		if ( null !== $link_depth && $processor->get_current_depth() < $link_depth ) {
			$links[] = array(
				'href' => $current_href,
				'text' => $current_text,
			);

			$collecting = false;
			$link_depth = null;
			$current_href = null;
			$current_text = '';
		}
	}

	if ( $collecting && null !== $current_href ) {
		$links[] = array(
			'href' => $current_href,
			'text' => $current_text,
		);
	}

	return $links;
}
