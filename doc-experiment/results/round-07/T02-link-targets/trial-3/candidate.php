<?php

/**
 * Sets the target attribute to '_blank' for all A tags with an href attribute.
 *
 * @param string $html The HTML string to process.
 * @return string The modified HTML with target="_blank" set on A tags that have href.
 */
function add_link_targets( string $html ): string {
	$processor = new WP_HTML_Tag_Processor( $html );
	
	while ( $processor->next_tag( array( 'tag_name' => 'a' ) ) ) {
		// Check if the tag has an href attribute (including href="" or href with no value)
		$href = $processor->get_attribute( 'href' );
		if ( $href !== null ) {
			// href attribute exists (could be empty string "" or true for valueless href)
			$processor->set_attribute( 'target', '_blank' );
		}
	}
	
	return $processor->get_updated_html();
}
