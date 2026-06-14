<?php
function table_to_array( string $html ): array {
	if ( ! class_exists( 'WP_HTML_Processor' ) ) {
		return array();
	}

	$processor = WP_HTML_Processor::create_fragment( $html );
	if ( ! $processor ) {
		return array();
	}

	if ( ! $processor->next_tag( 'TABLE' ) ) {
		return array();
	}

	$table_depth = $processor->get_current_depth();
	$rows        = array();
	$current_row = null;
	$current_cell = null;
	$row_depth   = null;
	$cell_depth   = null;

	$flush_cell = static function () use ( & $current_row, & $current_cell, & $cell_depth ) : void {
		if ( null !== $current_cell && null !== $current_row ) {
			$current_row[] = $current_cell;
		}

		$current_cell = null;
		$cell_depth   = null;
	};

	$flush_row = static function () use ( & $rows, & $current_row, & $current_cell, & $cell_depth, & $row_depth, $flush_cell ) : void {
		$flush_cell();

		if ( null !== $current_row ) {
			$rows[] = $current_row;
		}

		$current_row = null;
		$row_depth   = null;
	};

	while ( $processor->next_token() && $processor->get_current_depth() >= $table_depth ) {
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
		if ( 'TR' === $tag ) {
			if ( $processor->is_tag_closer() ) {
				if ( null !== $current_row ) {
					$flush_row();
				}
				continue;
			}

			if ( null !== $current_row ) {
				$flush_row();
			}

			$current_row = array();
			$row_depth   = $processor->get_current_depth();
			continue;
		}

		if ( 'TD' === $tag || 'TH' === $tag ) {
			if ( $processor->is_tag_closer() ) {
				$flush_cell();
				continue;
			}

			if ( null === $current_row ) {
				$current_row = array();
				$row_depth   = $processor->get_current_depth();
			}

			$flush_cell();
			$current_cell = '';
			$cell_depth   = $processor->get_current_depth();
			continue;
		}

		if ( null !== $current_cell && null !== $cell_depth && $processor->is_tag_closer() && $processor->get_current_depth() < $cell_depth ) {
			$flush_cell();
		}

		if ( null !== $current_row && null !== $row_depth && $processor->is_tag_closer() && $processor->get_current_depth() < $row_depth ) {
			$flush_row();
		}
	}

	if ( null !== $current_row ) {
		$flush_row();
	}

	return $rows;
}
