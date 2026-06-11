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
	$current_cell_text = '';
	$in_cell = false;
	$table_depth = $processor->get_current_depth();

	// Walk through tokens within the table
	while ( $processor->next_token() ) {
		$current_depth = $processor->get_current_depth();

		// Stop when we exit the table
		if ( $current_depth < $table_depth ) {
			break;
		}

		$token_type = $processor->get_token_type();
		$tag_name = $processor->get_tag();

		// Handle opening tags for TR
		if ( $token_type === '#tag' && 'TR' === $tag_name && ! $processor->is_tag_closer() ) {
			// If we have a previous row, save it
			if ( ! empty( $current_row ) ) {
				$rows[] = $current_row;
				$current_row = array();
			}
		}

		// Handle closing tags for TR
		if ( $token_type === '#tag' && 'TR' === $tag_name && $processor->is_tag_closer() ) {
			// Save the current cell if we're in one
			if ( $in_cell ) {
				$current_row[] = $current_cell_text;
				$current_cell_text = '';
				$in_cell = false;
			}
		}

		// Handle opening tags for TD and TH
		if ( $token_type === '#tag' && ( 'TD' === $tag_name || 'TH' === $tag_name ) && ! $processor->is_tag_closer() ) {
			// Save previous cell if any
			if ( $in_cell ) {
				$current_row[] = $current_cell_text;
				$current_cell_text = '';
			}
			$in_cell = true;
		}

		// Handle closing tags for TD and TH
		if ( $token_type === '#tag' && ( 'TD' === $tag_name || 'TH' === $tag_name ) && $processor->is_tag_closer() ) {
			if ( $in_cell ) {
				$current_row[] = $current_cell_text;
				$current_cell_text = '';
				$in_cell = false;
			}
		}

		// Collect text content from #text nodes when inside a cell
		if ( $in_cell && $token_type === '#text' ) {
			$current_cell_text .= $processor->get_modifiable_text();
		}
	}

	// Don't forget the last row
	if ( ! empty( $current_row ) ) {
		$rows[] = $current_row;
	}

	return $rows;
}
