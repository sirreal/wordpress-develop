<?php
function add_image_class( string $html ): string {
	$tags = new WP_HTML_Tag_Processor( $html );

	while ( $tags->next_tag( 'img' ) ) {
		$tags->add_class( 'wp-image' );
	}

	return $tags->get_updated_html();
}
