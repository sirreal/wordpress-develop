<?php

function add_link_targets( string $html ): string {
	$processor = new WP_HTML_Tag_Processor( $html );

	while ( $processor->next_tag( array( 'tag_name' => 'a' ) ) ) {
		// Check if the href attribute exists (not null)
		if ( null !== $processor->get_attribute( 'href' ) ) {
			// Set the target attribute to _blank
			$processor->set_attribute( 'target', '_blank' );
		}
	}

	return $processor->get_updated_html();
}
