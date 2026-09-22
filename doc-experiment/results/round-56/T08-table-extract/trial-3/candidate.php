<?php

if ( ! function_exists( 'table_to_array' ) ) {
	function table_to_array( string $html ): array {
		$processor = WP_HTML_Processor::create_fragment( $html );
		if ( null === $processor ) {
			return array();
		}

		if ( ! $processor->next_tag( 'TABLE' ) ) {
			return array();
		}

		$table_depth = $processor->get_current_depth();
		$rows        = array();
		$current_row = null;
		$current_cell = null;

		while ( $processor->next_token() && $processor->get_current_depth() >= $table_depth ) {
			$token_type = $processor->get_token_type();
			$token_name = $processor->get_token_name();

			if ( '#text' === $token_type ) {
				if ( null !== $current_cell ) {
					$current_cell .= $processor->get_modifiable_text();
				}
				continue;
			}

			if ( '#tag' !== $token_type || null === $token_name ) {
				continue;
			}

			if ( 'TR' === $token_name ) {
				if ( $processor->is_tag_closer() ) {
					if ( null !== $current_row ) {
						if ( null !== $current_cell ) {
							$current_row[] = $current_cell;
							$current_cell   = null;
						}
						$rows[] = $current_row;
					}
					$current_row  = null;
					$current_cell = null;
				} else {
					$current_row  = array();
					$current_cell = null;
				}
				continue;
			}

			if ( 'TD' === $token_name || 'TH' === $token_name ) {
				if ( $processor->is_tag_closer() ) {
					if ( null !== $current_row ) {
						$current_row[] = null === $current_cell ? '' : $current_cell;
					}
					$current_cell = null;
				} else {
					$current_cell = '';
				}
			}
		}

		return $rows;
	}
}
