<?php

function table_to_array( string $html ): array {
	$processor = WP_HTML_Processor::create_fragment( $html );
	
	if ( ! $processor || ! $processor->next_tag( array( 'tag_name' => 'table' ) ) ) {
		return array();
	}
	
	$table_depth = $processor->get_current_depth();
	$rows = array();
	$current_row = null;
	$current_cell_text = '';
	
	while ( $processor->next_token() ) {
		$depth = $processor->get_current_depth();
		$token_type = $processor->get_token_type();
		$tag = $processor->get_tag();
		$is_closer = $processor->is_tag_closer();
		
		// Stop when we exit the table
		if ( $depth < $table_depth ) {
			break;
		}
		
		if ( 'TR' === $tag ) {
			if ( ! $is_closer ) {
				// Opening TR - start a new row
				$current_row = array();
			} else {
				// Closing TR - save the row
				if ( null !== $current_row ) {
					$rows[] = $current_row;
					$current_row = null;
				}
			}
		} elseif ( ( 'TD' === $tag || 'TH' === $tag ) ) {
			if ( ! $is_closer ) {
				// Opening TD/TH - prepare to collect text
				$current_cell_text = '';
			} else {
				// Closing TD/TH - save the cell text
				if ( null !== $current_row ) {
					$current_row[] = $current_cell_text;
				}
				$current_cell_text = '';
			}
		} elseif ( '#text' === $token_type && null !== $current_row ) {
			// Collect text content
			$current_cell_text .= $processor->get_modifiable_text();
		}
	}
	
	return $rows;
}
