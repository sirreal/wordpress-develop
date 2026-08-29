<?php

function mark_last_h2( string $html ): string {
	$processor = new WP_HTML_Tag_Processor( $html );
	$last_h2_found = false;
	
	// Scan through all H2 tags and remember the last one found
	while ( $processor->next_tag( array( 'tag_name' => 'h2' ) ) ) {
		$last_h2_found = true;
	}
	
	// If we found at least one H2, go back and add the class to the last one
	if ( $last_h2_found ) {
		// Reset the processor and iterate again to find the last H2
		$processor = new WP_HTML_Tag_Processor( $html );
		$h2_count = 0;
		$last_h2_position = 0;
		
		// Count all H2 tags to know which one is the last
		while ( $processor->next_tag( array( 'tag_name' => 'h2' ) ) ) {
			$h2_count++;
		}
		
		// Now find the H2 at the last position
		if ( $h2_count > 0 ) {
			$processor = new WP_HTML_Tag_Processor( $html );
			$current_h2 = 0;
			
			while ( $processor->next_tag( array( 'tag_name' => 'h2' ) ) ) {
				$current_h2++;
				if ( $current_h2 === $h2_count ) {
					// This is the last H2, add the class
					$processor->add_class( 'final-section' );
					break;
				}
			}
		}
	}
	
	return $processor->get_updated_html();
}
