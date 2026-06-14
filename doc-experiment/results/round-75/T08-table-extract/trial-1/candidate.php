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

		$tag = $processor->get_tag();
		if ( null === $tag ) {
			continue;
		}

		if ( 'TR' === $tag ) {
			if ( $processor->is_tag_closer() ) {
				if ( null !== $current_cell ) {
					$current_row[] = $current_cell;
					$current_cell   = null;
					$cell_depth     = null;
				}

				if ( null !== $current_row ) {
					$rows[] = $current_row;
					$current_row = null;
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
			if ( null !== $current_cell ) {
				$current_row[] = $current_cell;
				$current_cell   = null;
				$cell_depth     = null;
			}
			continue;
		}

		$current_cell = '';
		$cell_depth   = $processor->get_current_depth();

		while ( $processor->next_token() ) {
			if ( $processor->get_current_depth() < $table_depth ) {
				break 2;
			}

			if ( '#text' === $processor->get_token_type() ) {
				$current_cell .= $processor->get_modifiable_text();
				continue;
			}

			if ( '#tag' !== $processor->get_token_type() ) {
				continue;
			}

			$inner_tag = $processor->get_tag();
			if ( null === $inner_tag ) {
				continue;
			}

			if ( ( 'TD' === $inner_tag || 'TH' === $inner_tag ) && $processor->is_tag_closer() && $processor->get_current_depth() < $cell_depth ) {
				$current_row[] = $current_cell;
				$current_cell   = null;
				$cell_depth     = null;
				break;
			}

			if ( 'TR' === $inner_tag && $processor->is_tag_closer() ) {
				$current_row[] = $current_cell;
				$current_cell   = null;
				$cell_depth     = null;
				$rows[]        = $current_row;
				$current_row    = null;
				break 2;
			}
		}
	}

	if ( null !== $current_cell ) {
		if ( null === $current_row ) {
			$current_row = array();
		}
		$current_row[] = $current_cell;
	}

	if ( null !== $current_row ) {
		$rows[] = $current_row;
	}

	return $rows;
}
