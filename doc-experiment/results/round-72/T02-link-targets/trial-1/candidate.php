<?php

function add_link_targets( string $html ): string {
	if ( ! class_exists( 'WP_HTML_Tag_Processor' ) ) {
		return $html;
	}

	$processor = new WP_HTML_Tag_Processor( $html );

	while ( $processor->next_tag( 'a' ) ) {
		if ( null === $processor->get_attribute( 'href' ) ) {
			continue;
		}

		$processor->set_attribute( 'target', '_blank' );
	}

	return $processor->get_updated_html();
}
