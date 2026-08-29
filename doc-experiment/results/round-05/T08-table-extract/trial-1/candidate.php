<?php

function table_to_array( string $html ): array {
	$processor = WP_HTML_Processor::create_fragment( $html );
	
	// Find the first TABLE element
	if ( ! $processor->next_tag( array( 'tag_name' => 'TABLE' ) ) ) {
		return array();
	}
	
	$rows = array();
	$table_depth = $processor->get_current_depth();
	
	// Walk through the table contents
	while ( $processor->next_token() ) {
		$depth = $processor->get_current_depth();
		
		// Stop when we exit the table
		if ( $depth < $table_depth ) {
			break;
		}
		
		// Look for TR tags at the correct depth (direct children of table/tbody/thead)
		if ( '#tag' === $processor->get_token_type() && 'TR' === $processor->get_tag() && ! $processor->is_tag_closer() ) {
			$tr_depth = $processor->get_current_depth();
			$cells = array();
			
			// Walk through this row's contents
			while ( $processor->next_token() ) {
				$token_depth = $processor->get_current_depth();
				
				// Stop at the row closer or if we go back up
				if ( $token_depth <= $tr_depth && $processor->is_tag_closer() && 'TR' === $processor->get_tag() ) {
					break;
				}
				
				// Stop if we exit the row in other ways
				if ( $token_depth < $tr_depth ) {
					break;
				}
				
				// Look for TD or TH tags
				if ( '#tag' === $processor->get_token_type() && ( 'TD' === $processor->get_tag() || 'TH' === $processor->get_tag() ) && ! $processor->is_tag_closer() ) {
					$cell_depth = $processor->get_current_depth();
					$cell_text = '';
					
					// Collect all text content within the cell
					while ( $processor->next_token() ) {
						$text_depth = $processor->get_current_depth();
						
						// Stop at the cell closer
						if ( $text_depth <= $cell_depth && $processor->is_tag_closer() && ( 'TD' === $processor->get_tag() || 'TH' === $processor->get_tag() ) ) {
							break;
						}
						
						// Stop if we go back up
						if ( $text_depth < $cell_depth ) {
							break;
						}
						
						// Collect text nodes
						if ( '#text' === $processor->get_token_type() ) {
							$cell_text .= $processor->get_modifiable_text();
						}
					}
					
					$cells[] = $cell_text;
				}
			}
			
			if ( ! empty( $cells ) ) {
				$rows[] = $cells;
			}
		}
	}
	
	return $rows;
}
