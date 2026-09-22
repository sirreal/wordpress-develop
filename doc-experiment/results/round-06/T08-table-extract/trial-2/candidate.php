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
	$table_depth = $processor->get_current_depth();
	
	// Process tokens inside the table
	while ( $processor->next_token() ) {
		$depth = $processor->get_current_depth();
		
		// Stop when we exit the table
		if ( $depth < $table_depth ) {
			break;
		}
		
		$token_type = $processor->get_token_type();
		$tag_name = $processor->get_tag();
		
		// Process opening TR tags
		if ( '#tag' === $token_type && 'TR' === $tag_name && ! $processor->is_tag_closer() ) {
			// If we have a previous row, save it
			if ( ! empty( $current_row ) ) {
				$rows[] = $current_row;
				$current_row = array();
			}
		}
		
		// Process opening TD and TH tags
		if ( '#tag' === $token_type && ( 'TD' === $tag_name || 'TH' === $tag_name ) && ! $processor->is_tag_closer() ) {
			$cell_text = '';
			$cell_depth = $processor->get_current_depth();
			
			// Collect all text inside this cell
			while ( $processor->next_token() ) {
				$token_depth = $processor->get_current_depth();
				
				// Stop when we exit the cell
				if ( $token_depth < $cell_depth ) {
					break;
				}
				
				// Only collect text nodes that are direct content (not in nested tags)
				if ( '#text' === $processor->get_token_type() ) {
					$cell_text .= $processor->get_modifiable_text();
				}
			}
			
			$current_row[] = $cell_text;
		}
	}
	
	// Don't forget the last row
	if ( ! empty( $current_row ) ) {
		$rows[] = $current_row;
	}
	
	return $rows;
}
