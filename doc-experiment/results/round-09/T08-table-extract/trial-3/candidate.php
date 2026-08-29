<?php

function table_to_array( string $html ): array {
	$processor = WP_HTML_Processor::create_fragment( $html );
	
	if ( ! $processor || ! $processor->next_tag( array( 'tag_name' => 'table' ) ) ) {
		return array();
	}
	
	$rows = array();
	$current_row = array();
	$cell_depth = null;
	$cell_text = '';
	
	while ( $processor->next_token() ) {
		$tag_name = $processor->get_token_name();
		$token_type = $processor->get_token_type();
		$is_closer = $processor->is_tag_closer();
		
		// When we hit a TR opener, start collecting a row
		if ( 'TR' === $tag_name && ! $is_closer && '#tag' === $token_type ) {
			$current_row = array();
		}
		// When we hit a TR closer, save the current row
		else if ( 'TR' === $tag_name && $is_closer && '#tag' === $token_type ) {
			if ( ! empty( $current_row ) || count( $current_row ) > 0 ) {
				$rows[] = $current_row;
			}
			$current_row = array();
		}
		// When we hit a TD or TH opener, start collecting cell text
		else if ( ( 'TD' === $tag_name || 'TH' === $tag_name ) && ! $is_closer && '#tag' === $token_type ) {
			$cell_depth = $processor->get_current_depth();
			$cell_text = '';
		}
		// When we hit a TD or TH closer, save the cell text
		else if ( ( 'TD' === $tag_name || 'TH' === $tag_name ) && $is_closer && '#tag' === $token_type ) {
			if ( $cell_depth !== null ) {
				$current_row[] = $cell_text;
				$cell_depth = null;
				$cell_text = '';
			}
		}
		// Collect text nodes inside cells
		else if ( '#text' === $token_type && $cell_depth !== null ) {
			$cell_text .= $processor->get_modifiable_text();
		}
	}
	
	// Handle case where last row wasn't closed
	if ( ! empty( $current_row ) || count( $current_row ) > 0 ) {
		$rows[] = $current_row;
	}
	
	return $rows;
}
