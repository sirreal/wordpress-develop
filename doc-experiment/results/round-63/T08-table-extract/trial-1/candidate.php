<?php

function table_to_array( string $html ): array {
	if ( ! class_exists( 'WP_HTML_Processor' ) ) {
		return array();
	}

	$processor = WP_HTML_Processor::create_fragment( $html );
	if ( null === $processor ) {
		return array();
	}

	if ( ! $processor->next_tag( 'TABLE' ) || $processor->is_tag_closer() ) {
		return array();
	}

	$table_depth  = $processor->get_current_depth();
	$rows         = array();
	$current_row  = array();
	$current_cell = '';
	$in_row       = false;
	$in_cell      = false;
	$last_error   = null;

	$flush_cell = static function () use ( &$current_row, &$current_cell, &$in_cell ): void {
		if ( $in_cell ) {
			$current_row[] = $current_cell;
			$current_cell   = '';
			$in_cell        = false;
		}
	};

	$flush_row = static function () use ( &$rows, &$current_row, &$in_row, &$flush_cell ): void {
		$flush_cell();
		if ( $in_row ) {
			$rows[]   = $current_row;
			$current_row = array();
			$in_row   = false;
		}
	};

	while ( $processor->next_token() ) {
		if ( $processor->get_current_depth() < $table_depth ) {
			break;
		}

		if ( '#tag' !== $processor->get_token_type() ) {
			if ( $in_cell && '#text' === $processor->get_token_type() ) {
				$current_cell .= $processor->get_modifiable_text();
			}
			continue;
		}

		$tag_name = $processor->get_tag();
		if ( null === $tag_name ) {
			continue;
		}

		$is_closer = $processor->is_tag_closer();

		if ( 'TR' === $tag_name ) {
			if ( $is_closer ) {
				$flush_row();
			} else {
				if ( $in_row ) {
					$flush_row();
				}
				$in_row      = true;
				$current_row = array();
			}
			continue;
		}

		if ( 'TD' === $tag_name || 'TH' === $tag_name ) {
			if ( $is_closer ) {
				$flush_cell();
			} else {
				if ( ! $in_row ) {
					$in_row      = true;
					$current_row = array();
				}
				$flush_cell();
				$in_cell      = true;
				$current_cell = '';
			}
		}
	}

	if ( ! empty( $current_cell ) || $in_cell ) {
		$current_row[] = $current_cell;
	}
	if ( $in_row && ! empty( $current_row ) ) {
		$rows[] = $current_row;
	}

	$last_error = $processor->get_last_error();
	if ( null !== $last_error ) {
		// Best-effort extraction from supported content; ignore parser aborts.
	}

	return $rows;
}
