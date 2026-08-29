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
	
	$result = array();
	$current_row = array();
	$table_depth = $processor->get_current_depth();
	
	// Iterate through all tokens inside the table
	while ( $processor->next_token() ) {
		$current_depth = $processor->get_current_depth();
		$token_type = $processor->get_token_type();
		$tag_name = $processor->get_tag();
		
		// Exit when we drop below table depth (table closer)
		if ( $current_depth < $table_depth ) {
			if ( ! empty( $current_row ) ) {
				$result[] = $current_row;
			}
			break;
		}
		
		// Handle TR openers
		if ( '#tag' === $token_type && 'TR' === $tag_name && ! $processor->is_tag_closer() ) {
			// Save any pending row from previous TR
			if ( ! empty( $current_row ) ) {
				$result[] = $current_row;
			}
			$current_row = array();
		}
		
		// Handle TR closers
		if ( '#tag' === $token_type && 'TR' === $tag_name && $processor->is_tag_closer() ) {
			if ( ! empty( $current_row ) ) {
				$result[] = $current_row;
				$current_row = array();
			}
		}
		
		// Handle TD and TH cell openers
		if ( '#tag' === $token_type && ( 'TD' === $tag_name || 'TH' === $tag_name ) && ! $processor->is_tag_closer() ) {
			$cell_text = '';
			$cell_depth = $processor->get_current_depth();
			
			// Collect all text nodes inside this cell
			while ( $processor->next_token() ) {
				$token_depth = $processor->get_current_depth();
				
				// Stop when we exit the cell
				if ( $token_depth < $cell_depth ) {
					break;
				}
				
				// Collect text from #text tokens anywhere inside the cell
				if ( '#text' === $processor->get_token_type() ) {
					$cell_text .= $processor->get_modifiable_text();
				}
			}
			
			$current_row[] = $cell_text;
		}
	}
	
	return $result;
}
