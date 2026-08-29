<?php
function mark_nested_lists( string $html ): string {
	if ( ! class_exists( 'WP_HTML_Processor' ) ) {
		return $html;
	}

	$processor = WP_HTML_Processor::create_fragment( $html );
	if ( null === $processor ) {
		return $html;
	}

	while ( $processor->next_tag() ) {
		$tag = $processor->get_tag();
		if ( 'UL' !== $tag && 'OL' !== $tag ) {
			continue;
		}

		$ancestors = 0;
		foreach ( $processor->get_breadcrumbs() as $breadcrumb ) {
			if ( 'UL' === $breadcrumb || 'OL' === $breadcrumb ) {
				$ancestors++;
			}
		}

		if ( $ancestors > 1 ) {
			$processor->add_class( 'nested-list' );
		}
	}

	return $processor->get_updated_html();
}
