<?php
function add_link_targets( string $html ): string {
	$processor = new WP_HTML_Tag_Processor( $html );
	
	while ( $processor->next_tag( array( 'tag_name' => 'a' ) ) ) {
		// Check if the tag has an href attribute
		// The attribute exists even when value is empty ("") or unquoted
		if ( null !== $processor->get_attribute( 'href' ) || true === $processor->get_attribute( 'href' ) ) {
			$processor->set_attribute( 'target', '_blank' );
		}
	}
	
	return $processor->get_updated_html();
}
