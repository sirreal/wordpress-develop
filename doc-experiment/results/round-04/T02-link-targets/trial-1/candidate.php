<?php
/**
 * Opens links in a new tab by setting target="_blank" on all A tags with href attributes.
 *
 * @param string $html The HTML content to process.
 * @return string The modified HTML with target="_blank" set on A tags containing href.
 */
function add_link_targets( string $html ): string {
	$processor = new WP_HTML_Tag_Processor( $html );
	
	// Loop through all tags, looking for 'A' tags
	while ( $processor->next_tag( array( 'tag_name' => 'A' ) ) ) {
		// Check if the tag has an href attribute
		// get_attribute() returns null if not present, true if boolean attribute, or string value
		$href = $processor->get_attribute( 'href' );
		
		// Only process if href attribute exists (including empty string value)
		if ( null !== $href ) {
			// Set the target attribute to _blank
			$processor->set_attribute( 'target', '_blank' );
		}
	}
	
	// Return the modified HTML
	return $processor->get_updated_html();
}
