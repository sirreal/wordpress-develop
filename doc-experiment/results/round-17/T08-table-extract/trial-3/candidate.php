<?php
function table_to_array( string $html ): array {
	$processor = WP_HTML_Processor::create_fragment( $html );
	if ( ! $processor || ! $processor->next_tag( 'TABLE' ) ) {
		return array();
	}

	$rows = array();
	$current_row = array();
	$current_cell_text = '';
	$in_cell = false;
	$table_depth = $processor->get_current_depth();

	while ( $processor->next_token() ) {
		$depth = $processor->get_current_depth();
		$token_type = $processor->get_token_type();
		$token_name = $processor->get_token_name();

		// Stop when we exit the table
		if ( $depth < $table_depth ) {
			break;
		}

		// Handle cell opening tags (TD or TH)
		if ( '#tag' === $token_type && ( 'TD' === $token_name || 'TH' === $token_name ) && ! $processor->is_tag_closer() ) {
			$in_cell = true;
			$current_cell_text = '';
		}
		// Handle cell closing tags
		elseif ( '#tag' === $token_type && ( 'TD' === $token_name || 'TH' === $token_name ) && $processor->is_tag_closer() ) {
			if ( $in_cell ) {
				$current_row[] = $current_cell_text;
				$current_cell_text = '';
				$in_cell = false;
			}
		}
		// Handle row closing tags
		elseif ( '#tag' === $token_type && 'TR' === $token_name && $processor->is_tag_closer() ) {
			if ( ! empty( $current_row ) ) {
				$rows[] = $current_row;
				$current_row = array();
			}
		}
		// Handle text nodes inside cells
		elseif ( '#text' === $token_type && $in_cell ) {
			$current_cell_text .= $processor->get_modifiable_text();
		}
	}

	// Handle any remaining row (in case table ends without explicit </tr>)
	if ( ! empty( $current_row ) ) {
		$rows[] = $current_row;
	}

	return $rows;
}
