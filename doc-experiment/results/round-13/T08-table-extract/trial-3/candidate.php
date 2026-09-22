<?php

function table_to_array( string $html ): array {
	$processor = WP_HTML_Processor::create_fragment( $html );
	if ( ! $processor ) {
		return array();
	}

	// Find the first TABLE element.
	if ( ! $processor->next_tag( array( 'tag_name' => 'TABLE' ) ) ) {
		return array();
	}

	$table_depth = $processor->get_current_depth();
	$rows        = array();
	$current_row = null;
	$cell_text   = null;

	// Iterate through all tokens within the table.
	while ( $processor->next_token() ) {
		$current_depth = $processor->get_current_depth();
		
		// Stop if we've left the table.
		if ( $current_depth < $table_depth ) {
			break;
		}

		$token_name = $processor->get_token_name();
		$token_type = $processor->get_token_type();

		// Handle TR (table row) opening tags.
		if ( 'TR' === $token_name && ! $processor->is_tag_closer() ) {
			$current_row = array();
		}
		// Handle TR closing tags - save the row.
		elseif ( 'TR' === $token_name && $processor->is_tag_closer() ) {
			if ( null !== $current_row ) {
				$rows[]     = $current_row;
				$current_row = null;
			}
		}
		// Handle TD and TH opening tags.
		elseif ( ( 'TD' === $token_name || 'TH' === $token_name ) && ! $processor->is_tag_closer() ) {
			$cell_text = '';
		}
		// Handle TD and TH closing tags.
		elseif ( ( 'TD' === $token_name || 'TH' === $token_name ) && $processor->is_tag_closer() ) {
			if ( null !== $current_row && null !== $cell_text ) {
				$current_row[] = $cell_text;
				$cell_text     = null;
			}
		}
		// Handle text nodes inside cells.
		elseif ( '#text' === $token_type && null !== $cell_text ) {
			$cell_text .= $processor->get_modifiable_text();
		}
	}

	// Handle case where TR was not closed at end of table.
	if ( null !== $current_row ) {
		$rows[] = $current_row;
	}

	return $rows;
}
