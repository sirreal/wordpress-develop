<?php

function table_to_array( string $html ): array {
	$processor = new WP_HTML_Tag_Processor( $html );

	// First, find the opening <table> tag.
	if ( ! $processor->next_tag( 'TABLE' ) ) {
		return array();
	}

	$rows        = array();
	$current_row = null; // null means not in a row yet.
	$current_cell_text = null; // null means not in a cell.

	/*
	 * Now scan through all tokens inside the table.
	 * We use next_token() to capture both tags and text nodes.
	 * Since WP_HTML_Tag_Processor is purely lexical, we must handle
	 * optional closing tags ourselves by treating certain openers
	 * as implicit closers for the previous element.
	 */
	while ( $processor->next_token() ) {
		$token_type = $processor->get_token_type();

		if ( '#tag' !== $token_type ) {
			// Text node: accumulate if we're inside a cell.
			if ( '#text' === $token_type && null !== $current_cell_text ) {
				$current_cell_text .= $processor->get_modifiable_text();
			}
			continue;
		}

		$tag_name  = $processor->get_tag();
		$is_closer = $processor->is_tag_closer();

		if ( $is_closer ) {
			switch ( $tag_name ) {
				case 'TD':
				case 'TH':
					// Close the current cell.
					if ( null !== $current_cell_text && null !== $current_row ) {
						$current_row[] = $current_cell_text;
					}
					$current_cell_text = null;
					break;

				case 'TR':
					// Close the current cell first (optional </td> may be omitted).
					if ( null !== $current_cell_text && null !== $current_row ) {
						$current_row[] = $current_cell_text;
					}
					$current_cell_text = null;
					// Close the current row.
					if ( null !== $current_row ) {
						$rows[]      = $current_row;
						$current_row = null;
					}
					break;

				case 'TBODY':
				case 'THEAD':
				case 'TFOOT':
					// Closing a section: close open cell and row.
					if ( null !== $current_cell_text && null !== $current_row ) {
						$current_row[] = $current_cell_text;
					}
					$current_cell_text = null;
					if ( null !== $current_row ) {
						$rows[]      = $current_row;
						$current_row = null;
					}
					break;

				case 'TABLE':
					// End of table: close open cell and row.
					if ( null !== $current_cell_text && null !== $current_row ) {
						$current_row[] = $current_cell_text;
					}
					$current_cell_text = null;
					if ( null !== $current_row ) {
						$rows[]      = $current_row;
						$current_row = null;
					}
					return $rows;
			}
		} else {
			// Opening tags.
			switch ( $tag_name ) {
				case 'TR':
					// A new <tr> implicitly closes any open cell and row.
					if ( null !== $current_cell_text && null !== $current_row ) {
						$current_row[] = $current_cell_text;
					}
					$current_cell_text = null;
					if ( null !== $current_row ) {
						$rows[]      = $current_row;
					}
					$current_row = array();
					break;

				case 'TD':
				case 'TH':
					// A new <td>/<th> implicitly closes any open cell.
					if ( null !== $current_cell_text && null !== $current_row ) {
						$current_row[] = $current_cell_text;
					}
					// Start collecting text for this cell.
					// If we somehow got a cell without a row, start a row.
					if ( null === $current_row ) {
						$current_row = array();
					}
					$current_cell_text = '';
					break;

				case 'TABLE':
					// Nested table: skip its content (task says assume no nesting,
					// but guard against it by not processing nested tables).
					// Since task guarantees no nesting, this shouldn't happen.
					break;
			}
		}
	}

	// Handle case where </table> was omitted or end of input reached.
	if ( null !== $current_cell_text && null !== $current_row ) {
		$current_row[] = $current_cell_text;
	}
	if ( null !== $current_row ) {
		$rows[] = $current_row;
	}

	return $rows;
}
