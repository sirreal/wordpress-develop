<?php
/**
 * Adds target="_blank" to all A tags with href attributes.
 *
 * @param string $html The HTML string to process.
 * @return string The modified HTML string.
 */
function add_link_targets( string $html ): string {
	$processor = new WP_HTML_Tag_Processor( $html );
	
	while ( $processor->next_tag( 'A' ) ) {
		// Check if the A tag has an href attribute
		// get_attribute returns null if not present
		$href = $processor->get_attribute( 'href' );
		
		if ( null !== $href ) {
			// href attribute is present, set target to _blank
			$processor->set_attribute( 'target', '_blank' );
		}
	}
	
	return $processor->get_updated_html();
}
