<?php
/**
 * Sets the target attribute to "_blank" for all A tags that have an href attribute.
 *
 * @param string $html The HTML string to modify.
 * @return string The modified HTML with target="_blank" set on all A tags with href.
 */
function add_link_targets( string $html ): string {
	$processor = new WP_HTML_Tag_Processor( $html );
	
	while ( $processor->next_tag( 'a' ) ) {
		// Only modify A tags that have an href attribute.
		// The href attribute counts as present even when its value is empty ("") 
		// or when written without a value (<a href>).
		if ( null !== $processor->get_attribute( 'href' ) || true === $processor->get_attribute( 'href' ) ) {
			$processor->set_attribute( 'target', '_blank' );
		}
	}
	
	return $processor->get_updated_html();
}
