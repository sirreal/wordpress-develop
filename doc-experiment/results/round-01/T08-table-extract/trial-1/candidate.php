<?php

function table_to_array( string $html ): array {
	$processor = new WP_HTML_Tag_Processor( $html );

	$rows   = array();
	$state  = 'outside'; // outside | in_table | in_row | in_cell
	$depth  = 0;         // track nested TABLE depth to find the *first* table's end
	$row    = array();
	$cell   = '';

	while ( $processor->next_token() ) {
		$token_type = $processor->get_token_type();

		if ( '#tag' === $token_type ) {
			$tag_name  = $processor->get_tag();
			$is_closer = $processor->is_tag_closer();

			if ( 'TABLE' === $tag_name ) {
				if ( ! $is_closer ) {
					if ( 'outside' === $state ) {
						$state = 'in_table';
						$depth = 1;
					} else {
						// nested table — not expected per task, but track depth
						$depth++;
					}
				} else {
					// closing TABLE
					$depth--;
					if ( $depth <= 0 ) {
						// Close out any open cell/row before returning.
						if ( 'in_cell' === $state ) {
							$row[] = $cell;
							$cell  = '';
						}
						if ( ( 'in_row' === $state || 'in_cell' === $state ) && count( $row ) > 0 ) {
							$rows[] = $row;
							$row    = array();
						}
						break; // done with the first table
					}
				}
				continue;
			}

			// Only process the following logic when we are inside the first table.
			if ( 'outside' === $state ) {
				continue;
			}

			if ( 'TR' === $tag_name ) {
				if ( ! $is_closer ) {
					// Implicit close of any open cell.
					if ( 'in_cell' === $state ) {
						$row[] = $cell;
						$cell  = '';
					}
					// Implicit close of any open row.
					if ( ( 'in_row' === $state || 'in_cell' === $state ) && count( $row ) > 0 ) {
						$rows[] = $row;
						$row    = array();
					}
					$state = 'in_row';
				} else {
					// Explicit </tr>
					if ( 'in_cell' === $state ) {
						$row[] = $cell;
						$cell  = '';
					}
					if ( count( $row ) > 0 ) {
						$rows[] = $row;
						$row    = array();
					}
					$state = 'in_table';
				}
				continue;
			}

			if ( 'TD' === $tag_name || 'TH' === $tag_name ) {
				if ( ! $is_closer ) {
					// Implicit close of previous cell if any.
					if ( 'in_cell' === $state ) {
						$row[] = $cell;
						$cell  = '';
					}
					// If we weren't in a row yet (e.g. no explicit <tr>), start one.
					if ( 'in_table' === $state ) {
						$state = 'in_row';
					}
					$state = 'in_cell';
				} else {
					// Explicit </td> or </th>
					if ( 'in_cell' === $state ) {
						$row[] = $cell;
						$cell  = '';
						$state = 'in_row';
					}
				}
				continue;
			}

			// All other tags inside the cell are ignored structurally;
			// their text children will still be picked up as #text tokens.
			continue;
		}

		// Accumulate text only when inside a cell.
		if ( '#text' === $token_type && 'in_cell' === $state ) {
			$cell .= $processor->get_modifiable_text();
		}
	}

	return $rows;
}
