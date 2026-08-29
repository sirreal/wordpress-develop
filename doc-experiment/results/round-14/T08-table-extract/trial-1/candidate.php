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
	
	$rows = array();
	$current_row = array();
	$current_cell_text = '';
	$inside_table = true;
	$table_depth = $processor->get_current_depth();
	
	while ( $processor->next_token() && $processor->get_current_depth() >= $table_depth ) {
		$tag_name = $processor->get_tag();
		$is_closer = $processor->is_tag_closer();
		
		// Handle TD and TH cells
		if ( ( 'TD' === $tag_name || 'TH' === $tag_name ) && ! $is_closer ) {
			// Opening tag - start collecting text for this cell
			$current_cell_text = '';
		} elseif ( ( 'TD' === $tag_name || 'TH' === $tag_name ) && $is_closer ) {
			// Closing tag - save the cell text
			$current_row[] = $current_cell_text;
			$current_cell_text = '';
		}
		// Handle TR closing - save the row
		elseif ( 'TR' === $tag_name && $is_closer ) {
			if ( ! empty( $current_row ) ) {
				$rows[] = $current_row;
				$current_row = array();
			}
		}
		// Collect text content from text nodes
		elseif ( '#text' === $processor->get_token_type() ) {
			$current_cell_text .= $processor->get_modifiable_text();
		}
	}
	
	return $rows;
}
