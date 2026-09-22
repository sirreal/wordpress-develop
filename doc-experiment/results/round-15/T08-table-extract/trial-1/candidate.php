<?php

function table_to_array( string $html ): array {
	// Create an HTML processor to parse the HTML
	$processor = WP_HTML_Processor::create_fragment( $html );
	
	if ( ! $processor ) {
		return array();
	}
	
	// Find the first TABLE element
	if ( ! $processor->next_tag( array( 'tag_name' => 'table' ) ) ) {
		return array();
	}
	
	$rows = array();
	$current_row = null;
	$current_cell_text = '';
	$in_cell = false;
	$table_depth = $processor->get_current_depth();
	
	// Step through all tokens inside the table
	while ( $processor->next_token() && $processor->get_current_depth() >= $table_depth ) {
		$token_type = $processor->get_token_type();
		$token_name = $processor->get_token_name();
		
		// Opening tag for a row
		if ( '#tag' === $token_type && 'TR' === $token_name && ! $processor->is_tag_closer() ) {
			// If we have a previous row being built, save it
			if ( null !== $current_row ) {
				if ( $in_cell ) {
					// Save any pending cell text
					$current_row[] = $current_cell_text;
					$current_cell_text = '';
					$in_cell = false;
				}
				$rows[] = $current_row;
			}
			$current_row = array();
		}
		// Opening tag for a cell (TD or TH)
		elseif ( '#tag' === $token_type && ( 'TD' === $token_name || 'TH' === $token_name ) && ! $processor->is_tag_closer() ) {
			// If we were in a previous cell, save its text
			if ( $in_cell ) {
				$current_row[] = $current_cell_text;
			}
			$current_cell_text = '';
			$in_cell = true;
		}
		// Closing tag for a cell
		elseif ( '#tag' === $token_type && ( 'TD' === $token_name || 'TH' === $token_name ) && $processor->is_tag_closer() ) {
			if ( $in_cell ) {
				$current_row[] = $current_cell_text;
				$current_cell_text = '';
				$in_cell = false;
			}
		}
		// Text node
		elseif ( '#text' === $token_type && $in_cell ) {
			$current_cell_text .= $processor->get_modifiable_text();
		}
	}
	
	// Save the last row if we were building one
	if ( null !== $current_row ) {
		if ( $in_cell ) {
			$current_row[] = $current_cell_text;
		}
		$rows[] = $current_row;
	}
	
	return $rows;
}
