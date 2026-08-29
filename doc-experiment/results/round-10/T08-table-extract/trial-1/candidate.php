<?php

function table_to_array( string $html ): array {
	$processor = WP_HTML_Processor::create_fragment( $html );
	
	if ( ! $processor || ! $processor->next_tag( 'TABLE' ) ) {
		return array();
	}
	
	$table_depth = $processor->get_current_depth();
	$rows = array();
	$current_row = array();
	$current_cell_text = '';
	
	while ( $processor->next_token() ) {
		$current_depth = $processor->get_current_depth();
		
		// Stop when we exit the table
		if ( $current_depth < $table_depth ) {
			break;
		}
		
		$token_type = $processor->get_token_type();
		$tag_name = $processor->get_tag();
		
		// Process text nodes
		if ( '#text' === $token_type ) {
			$current_cell_text .= $processor->get_modifiable_text();
		}
		// Process TD and TH opening tags
		elseif ( '#tag' === $token_type && ! $processor->is_tag_closer() && ( 'TD' === $tag_name || 'TH' === $tag_name ) ) {
			// Start a new cell
			$current_cell_text = '';
		}
		// Process TD and TH closing tags
		elseif ( '#tag' === $token_type && $processor->is_tag_closer() && ( 'TD' === $tag_name || 'TH' === $tag_name ) ) {
			// End the cell and add to current row
			$current_row[] = $current_cell_text;
			$current_cell_text = '';
		}
		// Process TR closing tags
		elseif ( '#tag' === $token_type && $processor->is_tag_closer() && 'TR' === $tag_name ) {
			// End the row if it has cells
			if ( ! empty( $current_row ) ) {
				$rows[] = $current_row;
				$current_row = array();
			}
		}
	}
	
	return $rows;
}
