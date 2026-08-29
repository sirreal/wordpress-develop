<?php

function table_to_array( string $html ): array {
	// Create a fragment processor from the HTML
	$processor = WP_HTML_Processor::create_fragment( $html );
	if ( null === $processor ) {
		return array();
	}

	// Find the first TABLE element
	if ( ! $processor->next_tag( array( 'tag_name' => 'TABLE' ) ) ) {
		return array();
	}

	$rows = array();
	$current_row = null;
	$current_cell_text = null;
	$table_depth = $processor->get_current_depth();

	// Walk through all tokens within the table using a single-pass approach
	while ( $processor->next_token() ) {
		$current_depth = $processor->get_current_depth();

		// Stop when we exit the table
		if ( $current_depth < $table_depth ) {
			break;
		}

		$token_type = $processor->get_token_type();
		$token_name = $processor->get_token_name();

		// Handle TR (table row) openers
		if ( '#tag' === $token_type && 'TR' === $token_name && ! $processor->is_tag_closer() ) {
			$current_row = array();
		}
		// Handle TR closers - flush the row
		elseif ( '#tag' === $token_type && 'TR' === $token_name && $processor->is_tag_closer() ) {
			if ( null !== $current_row ) {
				// Make sure any pending cell text is added
				if ( null !== $current_cell_text ) {
					$current_row[] = $current_cell_text;
					$current_cell_text = null;
				}
				$rows[] = $current_row;
				$current_row = null;
			}
		}
		// Handle TD and TH (table cell) openers
		elseif ( '#tag' === $token_type && ( 'TD' === $token_name || 'TH' === $token_name ) && ! $processor->is_tag_closer() ) {
			if ( null === $current_row ) {
				// Implicit row opening
				$current_row = array();
			}
			// Start collecting text for this cell
			$current_cell_text = '';
		}
		// Handle TD/TH closers
		elseif ( '#tag' === $token_type && ( 'TD' === $token_name || 'TH' === $token_name ) && $processor->is_tag_closer() ) {
			if ( null !== $current_cell_text ) {
				$current_row[] = $current_cell_text;
				$current_cell_text = null;
			}
		}
		// Accumulate text within cells
		elseif ( '#text' === $token_type && null !== $current_cell_text ) {
			$current_cell_text .= $processor->get_modifiable_text();
		}
	}

	// Handle unclosed final row
	if ( null !== $current_row ) {
		if ( null !== $current_cell_text ) {
			$current_row[] = $current_cell_text;
		}
		$rows[] = $current_row;
	}

	return $rows;
}
