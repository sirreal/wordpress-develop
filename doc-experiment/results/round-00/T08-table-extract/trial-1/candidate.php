<?php

function table_to_array( string $html ): array {
	$processor = new WP_HTML_Tag_Processor( $html );

	// Parser state.
	$STATE_OUTSIDE_TABLE = 0;
	$STATE_IN_TABLE      = 1;
	$STATE_IN_ROW        = 2;
	$STATE_IN_CELL       = 3;

	$state = $STATE_OUTSIDE_TABLE;

	$rows         = array();
	$current_row  = array();
	$current_cell = '';

	while ( $processor->next_token() ) {
		$token_type = $processor->get_token_type();

		if ( $STATE_OUTSIDE_TABLE === $state ) {
			// Look for the first TABLE opening tag.
			if ( '#tag' === $token_type
				&& 'TABLE' === $processor->get_tag()
				&& ! $processor->is_tag_closer()
			) {
				$state = $STATE_IN_TABLE;
			}
			continue;
		}

		if ( $STATE_IN_TABLE === $state ) {
			if ( '#tag' !== $token_type ) {
				continue;
			}
			$tag       = $processor->get_tag();
			$is_closer = $processor->is_tag_closer();

			if ( ! $is_closer && 'TR' === $tag ) {
				$state       = $STATE_IN_ROW;
				$current_row = array();
			} elseif ( $is_closer && 'TABLE' === $tag ) {
				break;
			}
			// THEAD, TBODY, TFOOT, CAPTION, COLGROUP, COL: silently ignored.
			continue;
		}

		if ( $STATE_IN_ROW === $state ) {
			if ( '#tag' !== $token_type ) {
				continue;
			}
			$tag       = $processor->get_tag();
			$is_closer = $processor->is_tag_closer();

			if ( ! $is_closer && ( 'TD' === $tag || 'TH' === $tag ) ) {
				$state        = $STATE_IN_CELL;
				$current_cell = '';
			} elseif ( $is_closer && 'TR' === $tag ) {
				// Explicit </tr>: finish row.
				if ( count( $current_row ) > 0 ) {
					$rows[] = $current_row;
				}
				$current_row = array();
				$state       = $STATE_IN_TABLE;
			} elseif ( ! $is_closer && 'TR' === $tag ) {
				// New <tr> with no </tr>: finish row, start new.
				if ( count( $current_row ) > 0 ) {
					$rows[] = $current_row;
				}
				$current_row = array();
				// Stay in STATE_IN_ROW.
			} elseif ( $is_closer && 'TABLE' === $tag ) {
				// </table> with optional </tr> omitted.
				if ( count( $current_row ) > 0 ) {
					$rows[] = $current_row;
				}
				break;
			}
			continue;
		}

		if ( $STATE_IN_CELL === $state ) {
			if ( '#text' === $token_type ) {
				$current_cell .= $processor->get_modifiable_text();
				continue;
			}

			if ( '#tag' !== $token_type ) {
				continue;
			}

			$tag       = $processor->get_tag();
			$is_closer = $processor->is_tag_closer();

			if ( $is_closer && ( 'TD' === $tag || 'TH' === $tag ) ) {
				// Explicit </td> or </th>: finish cell.
				$current_row[] = html_entity_decode( $current_cell, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
				$current_cell  = '';
				$state         = $STATE_IN_ROW;
			} elseif ( ! $is_closer && ( 'TD' === $tag || 'TH' === $tag ) ) {
				// New <td>/<th> with optional </td> omitted: finish cell, start new.
				$current_row[] = html_entity_decode( $current_cell, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
				$current_cell  = '';
				// Stay in STATE_IN_CELL for the new cell.
			} elseif ( $is_closer && 'TR' === $tag ) {
				// </tr> with optional </td> omitted: finish cell and row.
				$current_row[] = html_entity_decode( $current_cell, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
				$current_cell  = '';
				if ( count( $current_row ) > 0 ) {
					$rows[] = $current_row;
				}
				$current_row = array();
				$state       = $STATE_IN_TABLE;
			} elseif ( ! $is_closer && 'TR' === $tag ) {
				// New <tr> with optional </td>, </tr> omitted: finish cell and row.
				$current_row[] = html_entity_decode( $current_cell, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
				$current_cell  = '';
				if ( count( $current_row ) > 0 ) {
					$rows[] = $current_row;
				}
				$current_row = array();
				$state       = $STATE_IN_ROW;
			} elseif ( $is_closer && 'TABLE' === $tag ) {
				// </table> with optional </td>, </tr> omitted: finish everything.
				$current_row[] = html_entity_decode( $current_cell, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
				$current_cell  = '';
				if ( count( $current_row ) > 0 ) {
					$rows[] = $current_row;
				}
				break;
			}
			// Any other open/close tag inside a cell: silently ignored (markup contributes nothing).
		}
	}

	return $rows;
}
