<?php

/**
 * Add the class 'wp-image' to every IMG tag in an HTML document or fragment.
 *
 * @param string $html The HTML document or fragment to process.
 * @return string The modified HTML with 'wp-image' class added to all IMG tags.
 */
function add_image_class( string $html ): string {
	$processor = new WP_HTML_Tag_Processor( $html );
	
	while ( $processor->next_tag( array( 'tag_name' => 'img' ) ) ) {
		$processor->add_class( 'wp-image' );
	}
	
	return $processor->get_updated_html();
}
