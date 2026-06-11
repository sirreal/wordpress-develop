<?php

function table_to_array( string $html ): array {
	$processor = WP_HTML_Processor::create_fragment( $html );
	
	if ( ! $processor ) {
		return array();
	}
	
	// Find the first TABLE element
	if ( ! $processor->next_tag( 'TABLE' ) ) {
		return array();
	}
	
	$rows = array();
	$current_row = array();
	$current_cell_text = '';
	$in_cell = false;
	
	// Get the depth of the table element
	$table_depth = $processor->get_current_depth();
	
	// Iterate through all tokens inside the table
	while ( $processor->next_token() ) {
		$breadcrumbs = $processor->get_breadcrumbs();
		$current_depth = $processor->get_current_depth();
		
		// Stop when we exit the table
		if ( $current_depth <= $table_depth ) {
			break;
		}
		
		$token_type = $processor->get_token_type();
		$tag_name = $processor->get_tag();
		
		// Handle opening tags for TR, TD, TH
		if ( '#tag' === $token_type && ! $processor->is_tag_closer() ) {
			if ( 'TR' === $tag_name ) {
				// Start a new row (close previous row if any)
				if ( ! empty( $current_row ) || in_array( 'TR', $breadcrumbs, true ) ) {
					if ( ! empty( $current_row ) ) {
						$rows[] = $current_row;
						$current_row = array();
					}
				}
			} elseif ( 'TD' === $tag_name || 'TH' === $tag_name ) {
				// Start collecting text for a cell
				$in_cell = true;
				$current_cell_text = '';
			}
		}
		
		// Handle closing tags
		if ( '#tag' === $token_type && $processor->is_tag_closer() ) {
			if ( 'TD' === $tag_name || 'TH' === $tag_name ) {
				// End the cell and add it to current row
				if ( $in_cell ) {
					$current_row[] = $current_cell_text;
					$in_cell = false;
					$current_cell_text = '';
				}
			} elseif ( 'TR' === $tag_name ) {
				// End the row
				if ( ! empty( $current_row ) ) {
					$rows[] = $current_row;
					$current_row = array();
				}
			}
		}
		
		// Handle text nodes
		if ( '#text' === $token_type && $in_cell ) {
			$current_cell_text .= $processor->get_modifiable_text();
		}
	}
	
	// Handle unclosed row at end
	if ( ! empty( $current_row ) ) {
		$rows[] = $current_row;
	}
	
	return $rows;
}
