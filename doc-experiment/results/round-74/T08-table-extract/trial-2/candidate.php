<?php
function table_to_array( string $html ): array {
	if ( ! class_exists( 'WP_HTML_Processor' ) ) {
		return array();
	}

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
	$current_cell_tag = null;

	while ( $processor->next_token() && $processor->get_current_depth() >= $table_depth ) {
		$token_type = $processor->get_token_type();
		$token_name = $processor->get_token_name();
		$is_closer  = $processor->is_tag_closer();

		if ( '#tag' === $token_type ) {
			if ( ! $is_closer ) {
				if ( 'TR' === $token_name ) {
					$current_row = array();
				} elseif ( 'TD' === $token_name || 'TH' === $token_name ) {
					if ( null === $current_row ) {
						$current_row = array();
					}
					$current_cell     = '';
					$current_cell_tag  = $token_name;
				}
			} else {
				if ( ( 'TD' === $token_name || 'TH' === $token_name ) && null !== $current_cell ) {
					$current_row[]    = $current_cell;
					$current_cell     = null;
					$current_cell_tag = null;
				} elseif ( 'TR' === $token_name ) {
					if ( null !== $current_cell ) {
						$current_row[]    = $current_cell;
						$current_cell     = null;
						$current_cell_tag = null;
					}

					if ( null !== $current_row ) {
						$rows[]       = $current_row;
						$current_row  = null;
					}
				}
			}

			continue;
		}

		if ( '#text' === $token_type && null !== $current_cell ) {
			$current_cell .= $processor->get_modifiable_text();
		}
	}

	if ( null !== $current_cell && null !== $current_row ) {
		$current_row[] = $current_cell;
	}

	if ( null !== $current_row ) {
		$rows[] = $current_row;
	}

	return $rows;
}
