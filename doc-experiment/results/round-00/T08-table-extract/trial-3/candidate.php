<?php

function table_to_array( string $html ): array {
	$processor = new WP_HTML_Tag_Processor( $html );

	// Step 1: Find the first TABLE opening tag.
	$found_table = false;
	while ( $processor->next_token() ) {
		if ( '#tag' === $processor->get_token_type()
			&& 'TABLE' === $processor->get_tag()
			&& ! $processor->is_tag_closer()
		) {
			$found_table = true;
			break;
		}
	}

	if ( ! $found_table ) {
		return array();
	}

	// Step 2: Scan through the table contents, tracking rows and cells.
	$rows        = array();
	$current_row = null;  // null means not inside a TR
	$current_cell_text = null; // null means not inside a TD/TH

	while ( $processor->next_token() ) {
		$token_type = $processor->get_token_type();

		if ( '#tag' === $token_type ) {
			$tag_name  = $processor->get_tag();
			$is_closer = $processor->is_tag_closer();

			// End of table.
			if ( 'TABLE' === $tag_name && $is_closer ) {
				// Close any open cell and row.
				if ( null !== $current_cell_text && null !== $current_row ) {
					$current_row[] = $current_cell_text;
					$current_cell_text = null;
				}
				if ( null !== $current_row ) {
					$rows[]      = $current_row;
					$current_row = null;
				}
				break;
			}

			// Opening a TR: close current cell + row first if open.
			if ( 'TR' === $tag_name && ! $is_closer ) {
				// Finalize any open cell.
				if ( null !== $current_cell_text && null !== $current_row ) {
					$current_row[] = $current_cell_text;
					$current_cell_text = null;
				}
				// Finalize any open row.
				if ( null !== $current_row ) {
					$rows[]      = $current_row;
				}
				$current_row = array();
				continue;
			}

			// Closing a TR: close current cell and finalize the row.
			if ( 'TR' === $tag_name && $is_closer ) {
				if ( null !== $current_cell_text && null !== $current_row ) {
					$current_row[] = $current_cell_text;
					$current_cell_text = null;
				}
				if ( null !== $current_row ) {
					$rows[]      = $current_row;
					$current_row = null;
				}
				continue;
			}

			// Opening a TD or TH: close any open cell first.
			if ( ( 'TD' === $tag_name || 'TH' === $tag_name ) && ! $is_closer ) {
				// Finalize any previously open cell.
				if ( null !== $current_cell_text && null !== $current_row ) {
					$current_row[] = $current_cell_text;
				}
				// If no current row (e.g. omitted <tr>), start one.
				if ( null === $current_row ) {
					$current_row = array();
				}
				$current_cell_text = '';
				continue;
			}

			// Closing a TD or TH: finalize the cell.
			if ( ( 'TD' === $tag_name || 'TH' === $tag_name ) && $is_closer ) {
				if ( null !== $current_cell_text && null !== $current_row ) {
					$current_row[] = $current_cell_text;
					$current_cell_text = null;
				}
				continue;
			}
		} elseif ( '#text' === $token_type ) {
			// Accumulate text if we're inside a cell.
			if ( null !== $current_cell_text ) {
				$current_cell_text .= $processor->get_modifiable_text();
			}
		}
	}

	return $rows;
}
