<?php

function table_to_array( string $html ): array {
	$processor = WP_HTML_Processor::create_fragment( $html );
	
	// Find the first TABLE element
	if ( ! $processor->next_tag( 'TABLE' ) ) {
		return array();
	}
	
	$table_depth = $processor->get_current_depth();
	$rows        = array();
	$current_row = null;
	
	// Walk through all tokens inside the table
	while ( $processor->next_token() ) {
		$depth = $processor->get_current_depth();
		
		// Stop when we exit the table (depth less than table depth)
		if ( $depth < $table_depth ) {
			break;
		}
		
		$token_type = $processor->get_token_type();
		$token_name = $processor->get_token_name();
		
		// Handle TR (table row) openers
		if ( '#tag' === $token_type && 'TR' === $token_name && ! $processor->is_tag_closer() ) {
			// If we already have a row being built, save it
			if ( null !== $current_row ) {
				$rows[] = $current_row;
			}
			$current_row = array();
		}
		
		// Handle TR closers - save the current row
		if ( '#tag' === $token_type && 'TR' === $token_name && $processor->is_tag_closer() ) {
			if ( null !== $current_row ) {
				$rows[] = $current_row;
				$current_row = null;
			}
		}
		
		// Handle TD and TH cells
		if ( '#tag' === $token_type && ( 'TD' === $token_name || 'TH' === $token_name ) && ! $processor->is_tag_closer() ) {
			if ( null !== $current_row ) {
				$cell_text = '';
				$cell_depth = $processor->get_current_depth();
				
				// Walk through tokens inside the cell to collect text
				while ( $processor->next_token() ) {
					$token_depth = $processor->get_current_depth();
					
					// Stop when we exit the cell
					if ( $token_depth < $cell_depth ) {
						break;
					}
					
					// Only collect text from direct text nodes (not nested tags)
					if ( '#text' === $processor->get_token_type() ) {
						$cell_text .= $processor->get_modifiable_text();
					}
				}
				
				$current_row[] = $cell_text;
			}
		}
	}
	
	// Don't forget to add the last row if it exists
	if ( null !== $current_row ) {
		$rows[] = $current_row;
	}
	
	return $rows;
}
