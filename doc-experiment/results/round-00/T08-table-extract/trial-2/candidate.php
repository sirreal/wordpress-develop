<?php

function table_to_array( string $html ): array {
	$processor = new WP_HTML_Tag_Processor( $html );

	$rows        = array();
	$current_row = null;
	$current_cell_text = null;
	$in_table    = false;
	$in_cell     = false; // inside a TD or TH
	$table_depth = 0;     // track nesting depth of tables (not supported by task, but safe)

	while ( $processor->next_token() ) {
		$token_type = $processor->get_token_type();
		$token_name = $processor->get_token_name();

		if ( '#tag' === $token_type ) {
			$tag      = $processor->get_tag();
			$is_closer = $processor->is_tag_closer();

			if ( ! $in_table ) {
				// Look for the first TABLE opener.
				if ( 'TABLE' === $tag && ! $is_closer ) {
					$in_table    = true;
					$table_depth = 1;
					$current_row = null;
					$current_cell_text = null;
					$in_cell     = false;
				}
				continue;
			}

			// We're inside a table.
			if ( 'TABLE' === $tag ) {
				if ( $is_closer ) {
					// Close the current cell if open.
					if ( $in_cell && null !== $current_row ) {
						$current_row[] = $current_cell_text;
					}
					$in_cell = false;
					$current_cell_text = null;

					// Close the current row if open.
					if ( null !== $current_row ) {
						$rows[] = $current_row;
					}
					$current_row = null;

					// Done — found the first table's closer.
					break;
				} else {
					// Nested table — task says tables aren't nested, but skip gracefully.
					// We just keep going; this shouldn't happen per task assumptions.
				}
				continue;
			}

			if ( 'TR' === $tag ) {
				if ( ! $is_closer ) {
					// Close the current cell if open.
					if ( $in_cell && null !== $current_row ) {
						$current_row[] = $current_cell_text;
						$in_cell = false;
						$current_cell_text = null;
					}

					// Close the current row if open.
					if ( null !== $current_row ) {
						$rows[] = $current_row;
					}

					// Start a new row.
					$current_row = array();
				} else {
					// Explicit TR closer.
					if ( $in_cell && null !== $current_row ) {
						$current_row[] = $current_cell_text;
						$in_cell = false;
						$current_cell_text = null;
					}

					if ( null !== $current_row ) {
						$rows[] = $current_row;
						$current_row = null;
					}
				}
				continue;
			}

			if ( 'TD' === $tag || 'TH' === $tag ) {
				if ( ! $is_closer ) {
					// Close the previous cell if open (optional closing tag omitted).
					if ( $in_cell && null !== $current_row ) {
						$current_row[] = $current_cell_text;
					}

					// Start a new row implicitly if not in one yet.
					if ( null === $current_row ) {
						$current_row = array();
					}

					$in_cell = true;
					$current_cell_text = '';
				} else {
					// Explicit cell closer.
					if ( $in_cell && null !== $current_row ) {
						$current_row[] = $current_cell_text;
					}
					$in_cell = false;
					$current_cell_text = null;
				}
				continue;
			}

			// Other tags inside a cell: ignore (don't accumulate tag markup).
			continue;
		}

		if ( '#text' === $token_name ) {
			if ( $in_table && $in_cell ) {
				$current_cell_text .= $processor->get_modifiable_text();
			}
			continue;
		}
	}

	return $rows;
}
