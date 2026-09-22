<?php

function table_to_array( string $html ): array {
	// Create a fragment processor for the HTML
	$processor = WP_HTML_Processor::create_fragment( $html );
	
	// Return empty array if processor creation failed
	if ( null === $processor ) {
		return array();
	}
	
	// Find the first TABLE element
	if ( ! $processor->next_tag( array( 'tag_name' => 'TABLE' ) ) ) {
		return array();
	}
	
	$rows = array();
	$current_row = array();
	$in_table = true;
	$table_depth = $processor->get_current_depth();
	
	// Iterate through tokens inside the table
	while ( $processor->next_token() && $processor->get_current_depth() > $table_depth ) {
		$token_name = $processor->get_token_name();
		$token_type = $processor->get_token_type();
		
		// Handle TR (table row) opening tags
		if ( '#tag' === $token_type && 'TR' === $token_name && ! $processor->is_tag_closer() ) {
			// If we have a current row, add it to rows
			if ( ! empty( $current_row ) ) {
				$rows[] = $current_row;
				$current_row = array();
			}
		}
		// Handle TD and TH (table cell) closing tags
		elseif ( '#tag' === $token_type && ( 'TD' === $token_name || 'TH' === $token_name ) && $processor->is_tag_closer() ) {
			// Cell is closed, move to next
			continue;
		}
		// Handle text nodes
		elseif ( '#text' === $token_type ) {
			$text = $processor->get_modifiable_text();
			// Check if we're inside a cell by looking at breadcrumbs
			$breadcrumbs = $processor->get_breadcrumbs();
			if ( ! empty( $breadcrumbs ) ) {
				$last_element = end( $breadcrumbs );
				if ( 'TD' === $last_element || 'TH' === $last_element ) {
					// Add text to the current cell
					if ( empty( $current_row ) || ! isset( $current_row[ count( $current_row ) - 1 ] ) ) {
						$current_row[] = '';
					}
					$current_row[ count( $current_row ) - 1 ] .= $text;
				}
			}
		}
		// Handle cell opening tags
		elseif ( '#tag' === $token_type && ( 'TD' === $token_name || 'TH' === $token_name ) && ! $processor->is_tag_closer() ) {
			// Start a new cell
			$current_row[] = '';
		}
	}
	
	// Add the last row if it exists
	if ( ! empty( $current_row ) ) {
		$rows[] = $current_row;
	}
	
	return $rows;
}
