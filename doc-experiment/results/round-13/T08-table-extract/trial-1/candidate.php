<?php

function table_to_array( string $html ): array {
	$processor = WP_HTML_Processor::create_fragment( $html );
	
	if ( null === $processor ) {
		return array();
	}
	
	// Find the first TABLE element
	if ( ! $processor->next_tag( array( 'tag_name' => 'TABLE' ) ) ) {
		return array();
	}
	
	$result = array();
	$current_row = array();
	$depth_inside_table = $processor->get_current_depth();
	
	// Process all tokens inside the table
	while ( $processor->next_token() ) {
		$token_type = $processor->get_token_type();
		$token_name = $processor->get_token_name();
		$current_depth = $processor->get_current_depth();
		
		// Stop if we've exited the table
		if ( $current_depth < $depth_inside_table ) {
			break;
		}
		
		// Only process tokens at the correct depth for rows and cells
		if ( 'TR' === $token_name ) {
			if ( $processor->is_tag_closer() ) {
				// End of row - save it if it has content
				if ( ! empty( $current_row ) ) {
					$result[] = $current_row;
					$current_row = array();
				}
			}
			// Opening TR: reset for new row (handled implicitly at next cell)
		} elseif ( ( 'TD' === $token_name || 'TH' === $token_name ) && ! $processor->is_tag_closer() ) {
			// Start of a cell - collect all text inside it
			$cell_text = '';
			$cell_depth = $processor->get_current_depth();
			
			// Collect all text tokens inside this cell until it closes
			while ( $processor->next_token() ) {
				$cell_current_depth = $processor->get_current_depth();
				
				// If depth dropped below cell depth, we've exited the cell
				if ( $cell_current_depth < $cell_depth ) {
					break;
				}
				
				// Only collect text at direct children of the cell
				if ( '#text' === $processor->get_token_type() ) {
					$cell_text .= $processor->get_modifiable_text();
				}
			}
			
			// Add the cell to the current row
			$current_row[] = $cell_text;
		}
	}
	
	return $result;
}
