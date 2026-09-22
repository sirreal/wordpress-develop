<?php

function table_to_array( string $html ): array {
	if ( ! class_exists( 'WP_HTML_Processor' ) ) {
		return array();
	}

	$processor = WP_HTML_Processor::create_fragment( $html );
	if ( null === $processor ) {
		return array();
	}

	$table_started = false;
	$table_depth   = 0;

	$rows         = array();
	$current_row  = array();
	$row_open     = false;
	$in_cell      = false;
	$cell_tag     = null;
	$cell_text    = '';
	$cell_depth   = 0;

	while ( $processor->next_token() ) {
		$token_type = $processor->get_token_type();

		if ( ! $table_started ) {
			if ( '#tag' === $token_type && 'TABLE' === $processor->get_token_name() && ! $processor->is_tag_closer() ) {
				$table_started = true;
				$table_depth   = $processor->get_current_depth();
			}
			continue;
		}

		$token_name = $processor->get_token_name();

		if ( '#text' === $token_type ) {
			if ( $in_cell ) {
				$cell_text .= $processor->get_modifiable_text();
			}
			continue;
		}

		if ( '#tag' !== $token_type || null === $token_name ) {
			continue;
		}

		if ( 'TABLE' === $token_name && $processor->is_tag_closer() ) {
			if ( $in_cell ) {
				$current_row[] = $cell_text;
				$in_cell       = false;
				$cell_tag      = null;
				$cell_text     = '';
			}

			if ( $row_open ) {
				$rows[] = $current_row;
			}

			break;
		}

		if ( ! $processor->is_tag_closer() ) {
			if ( 'TR' === $token_name ) {
				if ( $row_open ) {
					if ( $in_cell ) {
						$current_row[] = $cell_text;
						$in_cell       = false;
						$cell_tag      = null;
						$cell_text     = '';
					}

					$rows[] = $current_row;
				}

				$current_row = array();
				$row_open    = true;
			}

			if ( 'TD' === $token_name || 'TH' === $token_name ) {
				if ( ! $row_open ) {
					$current_row = array();
					$row_open    = true;
				}

				$in_cell    = true;
				$cell_tag   = $token_name;
				$cell_text  = '';
				$cell_depth = $processor->get_current_depth();
			}

			continue;
		}

		if ( $in_cell && $token_name === $cell_tag ) {
			$current_row[] = $cell_text;
			$in_cell       = false;
			$cell_tag      = null;
			$cell_text     = '';
			continue;
		}

		if ( 'TR' === $token_name && $row_open ) {
			if ( $in_cell ) {
				$current_row[] = $cell_text;
				$in_cell       = false;
				$cell_tag      = null;
				$cell_text     = '';
			}

			$rows[]      = $current_row;
			$current_row = array();
			$row_open    = false;
		}
	}

	if ( $table_started && $row_open ) {
		if ( $in_cell ) {
			$current_row[] = $cell_text;
		}

		$rows[] = $current_row;
	}

	return $rows;
}
