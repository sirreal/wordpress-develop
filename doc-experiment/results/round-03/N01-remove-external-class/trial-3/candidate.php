<?php

function remove_external_class( string $html ): string {
	$processor = new WP_HTML_Tag_Processor( $html );
	
	// Loop through all A tags
	while ( $processor->next_tag( array( 'tag_name' => 'A' ) ) ) {
		// Check if the tag has the "external" class (case-sensitive).
		// We need to manually check each class because has_class is case-insensitive.
		// According to the documentation, class_list() provides access to class names.
		$has_external_class = false;
		foreach ( $processor->class_list() as $class_name ) {
			if ( 'external' === $class_name ) {
				$has_external_class = true;
				break;
			}
		}
		
		// If the tag has the external class, remove it
		if ( $has_external_class ) {
			$processor->remove_class( 'external' );
		}
	}
	
	return $processor->get_updated_html();
}
