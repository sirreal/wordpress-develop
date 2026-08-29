<?php
/**
 * Extracts table data from an HTML fragment.
 *
 * Finds the first TABLE element and returns its contents as a list of rows,
 * where each row is a list of cell text contents (TD and TH).
 *
 * @param string $html HTML fragment as found inside <body>.
 * @return array List of rows; each row is a list of cell text strings.
 */
function table_to_array( string $html ): array {
	$processor = new WP_HTML_Tag_Processor( $html );

	// Find the first TABLE opener.
	if ( ! $processor->next_tag( 'TABLE' ) ) {
		return array();
	}

	$rows        = array();
	$current_row = null;
	$current_cell = null;
	$in_cell     = false;

	while ( $processor->next_token() ) {
		$token_type = $processor->get_token_type();

		// Accumulate text when inside a cell.
		if ( '#text' === $token_type ) {
			if ( $in_cell ) {
				$current_cell .= $processor->get_modifiable_text();
			}
			continue;
		}

		// Only process tag tokens for state transitions.
		if ( '#tag' !== $token_type ) {
			continue;
		}

		$tag       = $processor->get_tag();
		$is_closer = $processor->is_tag_closer();

		if ( 'TABLE' === $tag ) {
			// End of the table (either </table> or a nested TABLE opener which
			// can't really exist here given the "no nested tables" assumption).
			if ( $is_closer ) {
				// Save any open cell.
				if ( $in_cell ) {
					if ( null === $current_row ) {
						$current_row = array();
					}
					$current_row[] = $current_cell;
					$in_cell       = false;
					$current_cell  = null;
				}
				// Save any open row.
				if ( null !== $current_row ) {
					$rows[]      = $current_row;
					$current_row = null;
				}
			}
			// Stop processing regardless (opener shouldn't appear inside a table).
			break;
		}

		if ( 'TR' === $tag ) {
			if ( $is_closer ) {
				// </tr>: close the current cell (if any) and save the row.
				if ( $in_cell ) {
					if ( null === $current_row ) {
						$current_row = array();
					}
					$current_row[] = $current_cell;
					$in_cell       = false;
					$current_cell  = null;
				}
				if ( null !== $current_row ) {
					$rows[]      = $current_row;
					$current_row = null;
				}
			} else {
				// <tr>: implicitly close the current cell and row (if any), then start a new row.
				if ( $in_cell ) {
					if ( null === $current_row ) {
						$current_row = array();
					}
					$current_row[] = $current_cell;
					$in_cell       = false;
					$current_cell  = null;
				}
				if ( null !== $current_row ) {
					$rows[]      = $current_row;
					$current_row = null;
				}
				// Start a new row.
				$current_row = array();
			}
			continue;
		}

		if ( 'TD' === $tag || 'TH' === $tag ) {
			if ( $is_closer ) {
				// </td> or </th>: close the current cell.
				if ( $in_cell ) {
					if ( null === $current_row ) {
						$current_row = array();
					}
					$current_row[] = $current_cell;
					$in_cell       = false;
					$current_cell  = null;
				}
			} else {
				// <td> or <th>: implicitly close the previous cell (if any) and start a new one.
				if ( $in_cell ) {
					if ( null === $current_row ) {
						$current_row = array();
					}
					$current_row[] = $current_cell;
					// $in_cell stays true; we'll reset below.
				} else {
					// Starting first cell in a row; ensure we have a row.
					if ( null === $current_row ) {
						$current_row = array();
					}
				}
				$in_cell      = true;
				$current_cell = '';
			}
			continue;
		}

		// For TBODY, THEAD, TFOOT and other structural tags: no state change needed.
		// (They don't close cells or rows in the normal case.)
	}

	return $rows;
}
