<?php
function mark_nested_lists( string $html ): string {
	$processor = WP_HTML_Processor::create_fragment( $html );

	if ( null === $processor ) {
		return $html;
	}

	while ( $processor->next_tag( array( 'tag_name' => null ) ) ) {
		$tag_name = $processor->get_tag();

		if ( 'UL' !== $tag_name && 'OL' !== $tag_name ) {
			continue;
		}

		$breadcrumbs = $processor->get_breadcrumbs();
		$ancestor_breadcrumbs = array_slice( $breadcrumbs, 0, -1 );

		foreach ( $ancestor_breadcrumbs as $ancestor_tag ) {
			if ( 'UL' === $ancestor_tag || 'OL' === $ancestor_tag ) {
				$processor->add_class( 'nested-list' );
				break;
			}
		}
	}

	return $processor->get_updated_html();
}
