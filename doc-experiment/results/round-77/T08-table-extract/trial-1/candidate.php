<?php

if ( ! function_exists( 'table_to_array' ) ) {
	function table_to_array( string $html ): array {
		$processor = WP_HTML_Processor::create_fragment( $html );
		if ( null === $processor ) {
			return array();
		}

		if ( ! $processor->next_tag( 'table' ) ) {
			return array();
		}

		$table_depth = $processor->get_current_depth();
		$rows        = array();
		$current_row = null;
		$current_cell_index = -1;

		while ( $processor->next_token() ) {
			if ( $processor->get_current_depth() < $table_depth ) {
				break;
			}

			if ( '#tag' !== $processor->get_token_type() ) {
				if ( null !== $current_row && -1 !== $current_cell_index && '#text' === $processor->get_token_type() ) {
					$current_row[ $current_cell_index ] .= $processor->get_modifiable_text();
				}
				continue;
			}

			$tag = $processor->get_tag();
			if ( 'TR' === $tag ) {
				if ( $processor->is_tag_closer() ) {
					if ( null !== $current_row ) {
						$rows[] = $current_row;
						$current_row = null;
						$current_cell_index = -1;
					}
				} else {
					$current_row = array();
				}
				continue;
			}

			if ( 'TD' !== $tag && 'TH' !== $tag ) {
				continue;
			}

			if ( $processor->is_tag_closer() ) {
				$current_cell_index = -1;
				continue;
			}

			if ( null === $current_row ) {
				$current_row = array();
			}

			$current_row[]      = '';
			$current_cell_index = array_key_last( $current_row );
		}

		return $rows;
	}
}
