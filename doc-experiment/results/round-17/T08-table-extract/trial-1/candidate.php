<?php

function table_to_array( string $html ): array {
	$processor = WP_HTML_Processor::create_fragment( $html );
	
	if ( ! $processor || ! $processor->next_tag( 'TABLE' ) ) {
		return array();
	}
	
	$rows = array();
	$current_row = array();
	$current_cell_text = '';
	$in_cell = false;
	$table_depth = $processor->get_current_depth();
	
	// Step through all tokens inside the table
	while ( $processor->next_token() ) {
		$depth = $processor->get_current_depth();
		
		// Exit when we leave the table
		if ( $depth <= $table_depth ) {
			break;
		}
		
		$token_name = $processor->get_token_name();
		$token_type = $processor->get_token_type();
		$is_closer = $processor->is_tag_closer();
		
		// Collect text from #text nodes
		if ( '#text' === $token_type && $in_cell ) {
			$current_cell_text .= $processor->get_modifiable_text();
		}
		// Open a cell (TD or TH)
		elseif ( ( 'TD' === $token_name || 'TH' === $token_name ) && ! $is_closer ) {
			$in_cell = true;
			$current_cell_text = '';
		}
		// Close a cell (TD or TH)
		elseif ( ( 'TD' === $token_name || 'TH' === $token_name ) && $is_closer ) {
			if ( $in_cell ) {
				$current_row[] = $current_cell_text;
				$in_cell = false;
				$current_cell_text = '';
			}
		}
		// Close a row (TR)
		elseif ( 'TR' === $token_name && $is_closer ) {
			// Save any unclosed cell
			if ( $in_cell ) {
				$current_row[] = $current_cell_text;
				$in_cell = false;
				$current_cell_text = '';
			}
			// Add the row if it has content
			if ( ! empty( $current_row ) ) {
				$rows[] = $current_row;
				$current_row = array();
			}
		}
	}
	
	// Save any remaining row
	if ( $in_cell ) {
		$current_row[] = $current_cell_text;
	}
	if ( ! empty( $current_row ) ) {
		$rows[] = $current_row;
	}
	
	return $rows;
}
