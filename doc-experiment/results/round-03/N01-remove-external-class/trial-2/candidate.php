<?php

function remove_external_class( string $html ): string {
	$processor = new WP_HTML_Tag_Processor( $html );
	
	while ( $processor->next_tag( 'a' ) ) {
		$class_attr = $processor->get_attribute( 'class' );
		
		// Only process if the tag has a class attribute
		if ( $class_attr === null ) {
			continue;
		}
		
		// Split the classes into an array
		$classes = explode( ' ', $class_attr );
		$filtered_classes = array();
		
		// Filter out the 'external' class (case-sensitive)
		foreach ( $classes as $class ) {
			if ( $class !== 'external' ) {
				$filtered_classes[] = $class;
			}
		}
		
		// If all classes were removed, remove the attribute
		if ( empty( $filtered_classes ) ) {
			$processor->remove_attribute( 'class' );
		} elseif ( count( $filtered_classes ) < count( $classes ) ) {
			// If some classes were removed, update the attribute
			$processor->set_attribute( 'class', implode( ' ', $filtered_classes ) );
		}
	}
	
	return $processor->get_updated_html();
}
