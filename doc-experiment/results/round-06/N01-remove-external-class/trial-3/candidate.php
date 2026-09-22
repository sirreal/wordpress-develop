<?php

function remove_external_class( string $html ): string {
	$processor = new WP_HTML_Tag_Processor( $html );
	
	while ( $processor->next_tag( 'A' ) ) {
		// Check if this tag has the "external" class (case-sensitive check)
		$has_external = false;
		foreach ( $processor->class_list() as $class ) {
			if ( 'external' === $class ) {
				$has_external = true;
				break;
			}
		}
		
		// If the tag has the external class, remove it
		if ( $has_external ) {
			$processor->remove_class( 'external' );
		}
	}
	
	return $processor->get_updated_html();
}
