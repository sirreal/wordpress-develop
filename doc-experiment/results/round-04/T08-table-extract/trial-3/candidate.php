<?php

function table_to_array( string $html ): array {
	$processor = WP_HTML_Processor::create_fragment( $html );
	
	if ( ! $processor || ! $processor->next_tag( 'TABLE' ) ) {
		return array();
	}
	
	$rows = array();
	$current_row = array();
	$cell_text = '';
	$in_cell = false;
	$table_depth = $processor->get_current_depth();
	
	while ( $processor->next_token() && $processor->get_current_depth() > $table_depth ) {
		$token_type = $processor->get_token_type();
		$tag = $processor->get_tag();
		
		if ( '#tag' === $token_type ) {
			if ( ( 'TR' === $tag || 'TD' === $tag || 'TH' === $tag ) && ! $processor->is_tag_closer() ) {
				if ( 'TR' === $tag ) {
					// Starting a new row
					if ( ! empty( $current_row ) ) {
						$rows[] = $current_row;
						$current_row = array();
					}
				} elseif ( 'TD' === $tag || 'TH' === $tag ) {
					// Starting a new cell
					$in_cell = true;
					$cell_text = '';
				}
			} elseif ( ( 'TD' === $tag || 'TH' === $tag ) && $processor->is_tag_closer() ) {
				// Ending a cell
				if ( $in_cell ) {
					$current_row[] = $cell_text;
					$in_cell = false;
					$cell_text = '';
				}
			}
		} elseif ( '#text' === $token_type && $in_cell ) {
			// Accumulate text content of the cell
			$cell_text .= $processor->get_modifiable_text();
		}
	}
	
	// Add the last row if it exists
	if ( ! empty( $current_row ) ) {
		$rows[] = $current_row;
	}
	
	return $rows;
}
