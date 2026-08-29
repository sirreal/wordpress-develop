<?php

function add_link_targets( string $html ): string {
	$processor = new WP_HTML_Tag_Processor( $html );

	while ( $processor->next_tag( array( 'tag_name' => 'a' ) ) ) {
		// Check if the A tag has an href attribute
		// According to the documentation, get_attribute() returns:
		// - null if the attribute wasn't present
		// - "" (empty string) if present but empty
		// - true for boolean attributes
		// - the string value for normal attributes
		$href = $processor->get_attribute( 'href' );

		// If href attribute is present (including empty values and boolean forms)
		if ( null !== $href ) {
			// Set the target attribute to _blank
			$processor->set_attribute( 'target', '_blank' );
		}
	}

	return $processor->get_updated_html();
}
