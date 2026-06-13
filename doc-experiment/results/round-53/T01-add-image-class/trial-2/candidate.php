<?php

declare( strict_types=1 );

function add_image_class( string $html ): string {
	$processor = new WP_HTML_Tag_Processor( $html );

	while ( $processor->next_tag( 'img' ) ) {
		$processor->add_class( 'wp-image' );
	}

	return $processor->get_updated_html();
}
