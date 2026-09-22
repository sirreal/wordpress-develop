<?php

function add_link_targets( string $html ): string {
	$processor = new WP_HTML_Tag_Processor( $html );
	
	while ( $processor->next_tag( array( 'tag_name' => 'a' ) ) ) {
		// Check if the href attribute exists (including empty values like href="" or <a href>)
		$href = $processor->get_attribute( 'href' );
		
		// According to documentation, get_attribute() returns:
		// - null if attribute doesn't exist
		// - "" (empty string) if attribute exists but is empty
		// - true for boolean attributes
		// - the string value otherwise
		
		// We want to match any case where href is present (null means not present)
		if ( $href !== null ) {
			$processor->set_attribute( 'target', '_blank' );
		}
	}
	
	return $processor->get_updated_html();
}
