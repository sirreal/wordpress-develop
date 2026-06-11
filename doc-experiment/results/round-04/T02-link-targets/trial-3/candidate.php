<?php
/**
 * Open links in a new tab
 *
 * For every A tag that has an href attribute, set its target attribute to _blank.
 *
 * @param string $html The HTML to process.
 * @return string The modified HTML.
 */
function add_link_targets( string $html ): string {
	$processor = new WP_HTML_Tag_Processor( $html );
	
	while ( $processor->next_tag( 'a' ) ) {
		// Check if the current A tag has an href attribute
		// get_attribute returns null if not present, a string value or true if present
		$href = $processor->get_attribute( 'href' );
		
		// Only process if href attribute exists (not null)
		if ( null !== $href ) {
			$processor->set_attribute( 'target', '_blank' );
		}
	}
	
	return $processor->get_updated_html();
}
