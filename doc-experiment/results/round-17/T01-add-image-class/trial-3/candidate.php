<?php
/**
 * Add a class to every image tag in HTML.
 *
 * @param string $html HTML document or fragment.
 * @return string Modified HTML with wp-image class added to all IMG tags.
 */
function add_image_class( string $html ): string {
	$processor = new WP_HTML_Tag_Processor( $html );
	
	while ( $processor->next_tag( array( 'tag_name' => 'img' ) ) ) {
		$processor->add_class( 'wp-image' );
	}
	
	return $processor->get_updated_html();
}
