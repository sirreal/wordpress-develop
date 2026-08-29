<?php

function table_to_array( string $html ): array {
	$processor = WP_HTML_Processor::create_fragment( $html );
	if ( null === $processor ) {
		return array();
	}

	$rows         = array();
	$current_row  = null;
	$current_cell = null;
	$in_table     = false;

	while ( $processor->next_token() ) {
		$token_type = $processor->get_token_type();
		$tag_name   = $processor->get_tag();
		$breadcrumbs = $processor->get_breadcrumbs();

		// Check if TABLE is in the breadcrumbs to know if we're in a table
		$in_any_table = in_array( 'TABLE', $breadcrumbs, true );

		// Mark when we enter the first table
		if ( ! $in_table && '#tag' === $token_type && 'TABLE' === $tag_name && ! $processor->is_tag_closer() ) {
			$in_table = true;
			continue;
		}

		// If we're not in the first table we found, skip
		if ( ! $in_table ) {
			continue;
		}

		// If we've exited the table, finalize and stop
		if ( ! $in_any_table ) {
			if ( null !== $current_cell ) {
				// Cell was open, close it
				$current_row[] = $current_cell;
				$current_cell = null;
			}
			if ( null !== $current_row ) {
				$rows[] = $current_row;
				$current_row = null;
			}
			$in_table = false;
			break;
		}

		// Handle row opening
		if ( '#tag' === $token_type && 'TR' === $tag_name && ! $processor->is_tag_closer() ) {
			// Close previous cell if open
			if ( null !== $current_cell ) {
				if ( null === $current_row ) {
					$current_row = array();
				}
				$current_row[] = $current_cell;
				$current_cell = null;
			}
			// Close previous row if open
			if ( null !== $current_row ) {
				$rows[] = $current_row;
				$current_row = null;
			}
			// Start new row
			$current_row = array();
			continue;
		}

		// Handle row closing
		if ( '#tag' === $token_type && 'TR' === $tag_name && $processor->is_tag_closer() ) {
			// Close cell if open
			if ( null !== $current_cell ) {
				if ( null === $current_row ) {
					$current_row = array();
				}
				$current_row[] = $current_cell;
				$current_cell = null;
			}
			// Close row
			if ( null !== $current_row ) {
				$rows[] = $current_row;
				$current_row = null;
			}
			continue;
		}

		// Handle cell opening
		if ( '#tag' === $token_type && ( 'TD' === $tag_name || 'TH' === $tag_name ) && ! $processor->is_tag_closer() ) {
			// Ensure we have a row
			if ( null === $current_row ) {
				$current_row = array();
			}
			// Close previous cell if open
			if ( null !== $current_cell ) {
				$current_row[] = $current_cell;
			}
			// Start new cell
			$current_cell = '';
			continue;
		}

		// Handle cell closing
		if ( '#tag' === $token_type && ( 'TD' === $tag_name || 'TH' === $tag_name ) && $processor->is_tag_closer() ) {
			// Close cell
			if ( null === $current_row ) {
				$current_row = array();
			}
			$current_row[] = ( null !== $current_cell ) ? $current_cell : '';
			$current_cell = null;
			continue;
		}

		// Collect text in cells
		if ( '#text' === $token_type && null !== $current_cell ) {
			$current_cell .= $processor->get_modifiable_text();
		}
	}

	// Finalize any open row/cell
	if ( null !== $current_cell ) {
		if ( null === $current_row ) {
			$current_row = array();
		}
		$current_row[] = $current_cell;
	}
	if ( null !== $current_row ) {
		$rows[] = $current_row;
	}

	return $rows;
}
