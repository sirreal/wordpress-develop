<?php

function table_to_array( string $html ): array {
	$processor = WP_HTML_Processor::create_fragment( $html );
	
	if ( ! $processor || ! $processor->next_tag( array( 'tag_name' => 'TABLE' ) ) ) {
		return array();
	}
	
	$rows = array();
	$table_depth = $processor->get_current_depth();
	
	while ( $processor->next_token() && $processor->get_current_depth() > $table_depth ) {
		if ( 'TR' === $processor->get_token_name() && ! $processor->is_tag_closer() ) {
			$row = array();
			$tr_depth = $processor->get_current_depth();
			
			while ( $processor->next_token() && $processor->get_current_depth() > $tr_depth ) {
				if ( ( 'TD' === $processor->get_token_name() || 'TH' === $processor->get_token_name() ) && ! $processor->is_tag_closer() ) {
					$cell_text = '';
					$cell_depth = $processor->get_current_depth();
					
					while ( $processor->next_token() && $processor->get_current_depth() > $cell_depth ) {
						if ( '#text' === $processor->get_token_type() ) {
							$cell_text .= $processor->get_modifiable_text();
						}
					}
					
					$row[] = $cell_text;
				}
			}
			
			if ( ! empty( $row ) ) {
				$rows[] = $row;
			}
		}
	}
	
	return $rows;
}
