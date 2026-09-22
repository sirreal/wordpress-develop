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
	$cell_tag    = null;
	$cell_depth  = null;

	while ( $processor->next_token() ) {
		if ( $processor->get_current_depth() < $table_depth ) {
			break;
		}

		if ( '#text' === $processor->get_token_type() ) {
			if ( null !== $current_cell ) {
				$current_cell .= $processor->get_modifiable_text();
			}
			continue;
		}

		if ( '#tag' !== $processor->get_token_type() ) {
			continue;
		}

		$tag      = $processor->get_tag();
		$is_close = $processor->is_tag_closer();

		if ( 'TR' === $tag ) {
			if ( ! $is_close ) {
				$current_row = array();
			}

			if ( $is_close && null !== $current_row ) {
				$rows[]      = $current_row;
				$current_row = null;
			}

			continue;
		}

		if ( 'TD' !== $tag && 'TH' !== $tag ) {
			continue;
		}

		if ( ! $is_close ) {
			$current_cell = '';
			$cell_tag     = $tag;
			$cell_depth   = $processor->get_current_depth();
			continue;
		}

		if ( null !== $current_cell && $cell_tag === $tag ) {
			if ( null === $current_row ) {
				$current_row = array();
			}

			$current_row[] = $current_cell;
			$current_cell  = null;
			$cell_tag      = null;
			$cell_depth    = null;
		}
	}

	return $rows;
}
