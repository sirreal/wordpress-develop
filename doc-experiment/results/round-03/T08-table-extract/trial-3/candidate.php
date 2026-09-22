<?php
/**
 * Extract table data from HTML and return as array of rows.
 *
 * @param string $html HTML fragment to parse.
 * @return array Array of rows, each row is an array of cell text contents.
 */
function table_to_array( string $html ): array {
	$processor = WP_HTML_Processor::create_fragment( $html );
	
	if ( ! $processor || ! $processor->next_tag( array( 'tag_name' => 'TABLE' ) ) ) {
		return array();
	}
	
	$rows = array();
	$current_row = array();
	$in_table = true;
	$table_depth = $processor->get_current_depth();
	
	while ( $processor->next_token() && $processor->get_current_depth() > $table_depth ) {
		$token_type = $processor->get_token_type();
		$tag_name = $processor->get_tag();
		
		// Process TR (table row) opening tags
		if ( '#tag' === $token_type && 'TR' === $tag_name && ! $processor->is_tag_closer() ) {
			// Start a new row
			$current_row = array();
		}
		// Process TR closing tags or when we encounter a new TR (implicit close)
		elseif ( '#tag' === $token_type && 'TR' === $tag_name && $processor->is_tag_closer() ) {
			// End current row
			if ( ! empty( $current_row ) || count( $rows ) > 0 ) {
				$rows[] = $current_row;
				$current_row = array();
			}
		}
		// Process TD and TH opening tags
		elseif ( '#tag' === $token_type && ( 'TD' === $tag_name || 'TH' === $tag_name ) && ! $processor->is_tag_closer() ) {
			// Start collecting text for this cell
			$cell_text = '';
			$cell_depth = $processor->get_current_depth();
			
			// Collect all text nodes inside this cell
			while ( $processor->next_token() && $processor->get_current_depth() > $cell_depth ) {
				if ( '#text' === $processor->get_token_type() ) {
					$cell_text .= $processor->get_modifiable_text();
				}
			}
			
			$current_row[] = $cell_text;
		}
	}
	
	// Handle case where the last row wasn't explicitly closed
	if ( ! empty( $current_row ) ) {
		$rows[] = $current_row;
	}
	
	return $rows;
}
