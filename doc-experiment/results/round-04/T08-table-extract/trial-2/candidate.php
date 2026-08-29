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
	
	// Process all tokens inside the table
	while ( $processor->next_token() && $processor->get_current_depth() > $table_depth ) {
		$token_type = $processor->get_token_type();
		$tag_name = $processor->get_tag();
		
		// Handle TR opening tags
		if ( '#tag' === $token_type && 'TR' === $tag_name && ! $processor->is_tag_closer() ) {
			// Save previous row if it exists
			if ( ! empty( $current_row ) ) {
				$rows[] = $current_row;
				$current_row = array();
			}
		}
		// Handle TD and TH tags (only opening tags, text inside comes next)
		elseif ( '#tag' === $token_type && ( 'TD' === $tag_name || 'TH' === $tag_name ) && ! $processor->is_tag_closer() ) {
			$cell_text = '';
			$cell_depth = $processor->get_current_depth();
			
			// Collect all text nodes inside this cell
			while ( $processor->next_token() && $processor->get_current_depth() > $cell_depth ) {
				if ( '#text' === $processor->get_token_type() ) {
					$cell_text .= $processor->get_modifiable_text();
				}
			}
			
			$current_row[] = $cell_text;
		}
	}
	
	// Add the last row if it exists
	if ( ! empty( $current_row ) ) {
		$rows[] = $current_row;
	}
	
	return $rows;
}
