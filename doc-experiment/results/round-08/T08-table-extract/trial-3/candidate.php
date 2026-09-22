<?php

function table_to_array( string $html ): array {
	// Create an HTML processor for the fragment
	$processor = WP_HTML_Processor::create_fragment( $html );
	
	if ( $processor === null ) {
		return array();
	}
	
	// Find the first TABLE element
	if ( ! $processor->next_tag( array( 'tag_name' => 'TABLE' ) ) ) {
		return array();
	}
	
	$rows = array();
	$table_depth = $processor->get_current_depth();
	
	// Iterate through TR elements that are direct children of the table
	while ( $processor->next_token() && $processor->get_current_depth() > $table_depth ) {
		if ( 'TR' === $processor->get_tag() && ! $processor->is_tag_closer() ) {
			$row = array();
			$row_depth = $processor->get_current_depth();
			
			// Iterate through TD and TH elements that are direct children of the row
			while ( $processor->next_token() && $processor->get_current_depth() > $row_depth ) {
				$tag = $processor->get_tag();
				
				if ( ( 'TD' === $tag || 'TH' === $tag ) && ! $processor->is_tag_closer() ) {
					$cell_text = '';
					$cell_depth = $processor->get_current_depth();
					
					// Collect all text content inside this cell
					while ( $processor->next_token() && $processor->get_current_depth() > $cell_depth ) {
						if ( '#text' === $processor->get_token_type() ) {
							$cell_text .= $processor->get_modifiable_text();
						}
					}
					
					$row[] = $cell_text;
				}
			}
			
			if ( ! empty( $row ) ) {
				$rows[] = $row;
			}
		}
	}
	
	return $rows;
}
