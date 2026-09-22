<?php
function remove_external_class( string $html ): string {
	$processor = new WP_HTML_Tag_Processor( $html );
	
	while ( $processor->next_tag( array( 'tag_name' => 'A' ) ) ) {
		// Iterate through classes to find case-sensitive match for "external"
		foreach ( $processor->class_list() as $class_name ) {
			if ( 'external' === $class_name ) {
				$processor->remove_class( 'external' );
				break;
			}
		}
	}
	
	return $processor->get_updated_html();
}
