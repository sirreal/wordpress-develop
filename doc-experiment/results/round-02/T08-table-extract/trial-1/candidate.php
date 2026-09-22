<?php

function table_to_array( string $html ): array {
	// Create a processor for the HTML fragment
	$processor = WP_HTML_Processor::create_fragment( $html );
	if ( null === $processor ) {
		return array();
	}

	// Find the first TABLE element
	if ( ! $processor->next_tag( 'TABLE' ) ) {
		return array();
	}

	$rows = array();
	$current_row = array();
	$in_cell = false;
	$cell_text = '';
	$table_depth = $processor->get_current_depth();

	// Iterate through all tokens inside the table
	while ( $processor->next_token() && $processor->get_current_depth() > $table_depth ) {
		$token_type = $processor->get_token_type();
		$token_name = $processor->get_token_name();
		$is_closer = $processor->is_tag_closer();

		// Entering a cell (TD or TH)
		if ( '#tag' === $token_type && ( 'TD' === $token_name || 'TH' === $token_name ) && ! $is_closer ) {
			// If we were in a cell, close it first
			if ( $in_cell ) {
				$current_row[] = $cell_text;
				$cell_text = '';
			}
			$in_cell = true;
		}
		// Exiting a cell
		elseif ( '#tag' === $token_type && ( 'TD' === $token_name || 'TH' === $token_name ) && $is_closer ) {
			if ( $in_cell ) {
				$current_row[] = $cell_text;
				$cell_text = '';
				$in_cell = false;
			}
		}
		// Exiting a row
		elseif ( '#tag' === $token_type && 'TR' === $token_name && $is_closer ) {
			if ( $in_cell ) {
				$current_row[] = $cell_text;
				$cell_text = '';
				$in_cell = false;
			}
			if ( ! empty( $current_row ) ) {
				$rows[] = $current_row;
				$current_row = array();
			}
		}
		// Collecting text inside a cell
		elseif ( '#text' === $token_type && $in_cell ) {
			$cell_text .= $processor->get_modifiable_text();
		}
	}

	// Handle any unclosed row/cell at the end
	if ( $in_cell ) {
		$current_row[] = $cell_text;
	}
	if ( ! empty( $current_row ) ) {
		$rows[] = $current_row;
	}

	return $rows;
}
