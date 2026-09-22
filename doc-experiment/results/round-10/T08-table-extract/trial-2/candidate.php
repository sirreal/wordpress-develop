<?php
function table_to_array( string $html ): array {
	$processor = WP_HTML_Processor::create_fragment( $html );
	
	if ( ! $processor || ! $processor->next_tag( 'TABLE' ) ) {
		return array();
	}
	
	$rows = array();
	$table_depth = $processor->get_current_depth();
	
	while ( $processor->next_token() ) {
		$current_depth = $processor->get_current_depth();
		$token_type = $processor->get_token_type();
		$token_name = $processor->get_token_name();
		
		// Stop when we exit the table
		if ( $current_depth < $table_depth ) {
			break;
		}
		
		// Process TR (table row) opening tags
		if ( 'TR' === $token_name && ! $processor->is_tag_closer() ) {
			$row = array();
			$row_depth = $current_depth;
			
			// Collect all tokens within this row
			while ( $processor->next_token() && $processor->get_current_depth() >= $row_depth ) {
				$cell_token_type = $processor->get_token_type();
				$cell_token_name = $processor->get_token_name();
				$cell_depth = $processor->get_current_depth();
				
				// Match TD or TH opening tags at the direct child level of the TR
				if ( ( 'TD' === $cell_token_name || 'TH' === $cell_token_name ) && 
				     ! $processor->is_tag_closer() && 
				     $cell_depth === $row_depth + 1 ) {
					$cell_text = '';
					$cell_open_depth = $cell_depth;
					
					// Collect all text tokens within this cell
					while ( $processor->next_token() && $processor->get_current_depth() >= $cell_open_depth ) {
						if ( '#text' === $processor->get_token_type() ) {
							$cell_text .= $processor->get_modifiable_text();
						}
					}
					
					$row[] = $cell_text;
				}
			}
			
			if ( ! empty( $row ) ) {
				$rows[] = $row;
			}
		}
	}
	
	return $rows;
}
