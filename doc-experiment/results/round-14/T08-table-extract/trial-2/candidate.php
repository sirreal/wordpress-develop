<?php

function table_to_array( string $html ): array {
	$processor = \WP_HTML_Processor::create_fragment( $html );
	
	if ( ! $processor ) {
		return array();
	}
	
	// Find the first TABLE element
	if ( ! $processor->next_tag( array( 'tag_name' => 'table' ) ) ) {
		return array();
	}
	
	$result = array();
	$table_depth = $processor->get_current_depth();
	
	// Walk through all tokens inside the table
	while ( $processor->next_token() ) {
		$current_depth = $processor->get_current_depth();
		
		// Stop when we exit the table
		if ( $current_depth < $table_depth ) {
			break;
		}
		
		// Look for TR elements that are direct children of TABLE or TBODY/THEAD
		if ( 'TR' === $processor->get_token_name() && ! $processor->is_tag_closer() ) {
			$row = array();
			$tr_depth = $processor->get_current_depth();
			
			// Walk through the contents of this TR to find cells
			while ( $processor->next_token() ) {
				$token_depth = $processor->get_current_depth();
				
				// Stop when we exit the TR
				if ( $token_depth < $tr_depth ) {
					break;
				}
				
				// Collect text from TD and TH elements
				$token_name = $processor->get_token_name();
				if ( ( 'TD' === $token_name || 'TH' === $token_name ) && ! $processor->is_tag_closer() ) {
					$cell_text = '';
					$cell_depth = $processor->get_current_depth();
					
					// Walk through the contents of this cell to collect all text
					while ( $processor->next_token() ) {
						$content_depth = $processor->get_current_depth();
						
						// Stop when we exit the cell
						if ( $content_depth < $cell_depth ) {
							break;
						}
						
						// Collect text nodes
						if ( '#text' === $processor->get_token_type() ) {
							$cell_text .= $processor->get_modifiable_text();
						}
					}
					
					$row[] = $cell_text;
				}
			}
			
			if ( ! empty( $row ) ) {
				$result[] = $row;
			}
		}
	}
	
	return $result;
}
