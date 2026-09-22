<?php

function table_to_array( string $html ): array {
	$processor = WP_HTML_Processor::create_fragment( $html );
	
	if ( ! $processor ) {
		return array();
	}
	
	// Find the first TABLE element
	if ( ! $processor->next_tag( array( 'tag_name' => 'TABLE' ) ) ) {
		return array();
	}
	
	$rows = array();
	$current_row = array();
	$cell_text = '';
	$in_cell = false;
	$in_tr = false;
	$table_depth = $processor->get_current_depth();
	
	// Walk through all tokens inside the table
	while ( $processor->next_token() ) {
		$current_depth = $processor->get_current_depth();
		
		// Stop when we exit the table (depth goes below table's depth)
		if ( $current_depth < $table_depth ) {
			break;
		}
		
		$token_type = $processor->get_token_type();
		$tag = $processor->get_tag();
		$is_closer = $processor->is_tag_closer();
		
		if ( '#tag' === $token_type ) {
			// Opening tags
			if ( ! $is_closer ) {
				if ( 'TR' === $tag ) {
					$in_tr = true;
				} elseif ( ( 'TD' === $tag || 'TH' === $tag ) && $in_tr ) {
					// If we were in a cell, close it first
					if ( $in_cell ) {
						$current_row[] = $cell_text;
						$cell_text = '';
					}
					$in_cell = true;
				}
			} else {
				// Closing tags
				if ( 'TD' === $tag || 'TH' === $tag ) {
					if ( $in_cell ) {
						$current_row[] = $cell_text;
						$in_cell = false;
						$cell_text = '';
					}
				} elseif ( 'TR' === $tag ) {
					// End of row - if we were in a cell, close it first
					if ( $in_cell ) {
						$current_row[] = $cell_text;
						$in_cell = false;
						$cell_text = '';
					}
					if ( ! empty( $current_row ) ) {
						$rows[] = $current_row;
						$current_row = array();
					}
					$in_tr = false;
				}
			}
		} elseif ( '#text' === $token_type ) {
			// Extract text from text nodes
			if ( $in_cell ) {
				$cell_text .= $processor->get_modifiable_text();
			}
		}
	}
	
	// Add any remaining cell and row
	if ( $in_cell ) {
		$current_row[] = $cell_text;
		$in_cell = false;
	}
	if ( ! empty( $current_row ) ) {
		$rows[] = $current_row;
	}
	
	return $rows;
}
