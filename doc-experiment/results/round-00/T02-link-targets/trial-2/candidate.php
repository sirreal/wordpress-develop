<?php

function add_link_targets( string $html ): string {
	$processor = new WP_HTML_Tag_Processor( $html );

	while ( $processor->next_tag( 'a' ) ) {
		// get_attribute() returns null if the attribute is absent,
		// true for a boolean (valueless) attribute like <a href>,
		// or a string for <a href=""> or <a href="/x">.
		// Any non-null value means href is present.
		if ( null !== $processor->get_attribute( 'href' ) ) {
			$processor->set_attribute( 'target', '_blank' );
		}
	}

	return $processor->get_updated_html();
}
