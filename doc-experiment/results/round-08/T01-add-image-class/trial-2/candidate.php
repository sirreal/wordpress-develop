<?php

/**
 * Add the 'wp-image' class to every IMG tag in the HTML document.
 *
 * @param string $html HTML document or fragment.
 * @return string The modified HTML with 'wp-image' class added to all IMG tags.
 */
function add_image_class( string $html ): string {
	$processor = new WP_HTML_Tag_Processor( $html );
	
	while ( $processor->next_tag( array( 'tag_name' => 'img' ) ) ) {
		$processor->add_class( 'wp-image' );
	}
	
	return $processor->get_updated_html();
}
