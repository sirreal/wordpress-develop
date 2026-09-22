<?php

function add_image_class( string $html ): string {
	if ( ! class_exists( 'WP_HTML_Tag_Processor' ) ) {
		return $html;
	}

	$processor = new WP_HTML_Tag_Processor( $html );

	while ( $processor->next_tag( 'img' ) ) {
		$processor->add_class( 'wp-image' );
	}

	return $processor->get_updated_html();
}
