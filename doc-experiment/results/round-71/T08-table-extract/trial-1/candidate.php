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

	$flush_cell = static function () use ( &$current_row, &$current_cell ): void {
		if ( null === $current_row || null === $current_cell ) {
			return;
		}

		$current_row[] = $current_cell;
		$current_cell   = null;
	};

	$flush_row = static function () use ( &$rows, &$current_row, &$current_cell, $flush_cell ): void {
		if ( null === $current_row ) {
			return;
		}

		$flush_cell();
		$rows[]      = $current_row;
		$current_row = null;
	};

	while ( $processor->next_token() && $processor->get_current_depth() >= $table_depth ) {
		if ( '#tag' === $processor->get_token_type() ) {
			$tag_name  = $processor->get_tag();
			$is_closer = $processor->is_tag_closer();

			if ( 'TR' === $tag_name ) {
				if ( $is_closer ) {
					$flush_row();
				} else {
					if ( null !== $current_row ) {
						$flush_row();
					}
					$current_row = array();
				}
			} elseif ( 'TD' === $tag_name || 'TH' === $tag_name ) {
				if ( $is_closer ) {
					$flush_cell();
				} else {
					if ( null === $current_row ) {
						$current_row = array();
					}
					if ( null !== $current_cell ) {
						$flush_cell();
					}
					$current_cell = '';
				}
			}
		} elseif ( '#text' === $processor->get_token_type() && null !== $current_cell ) {
			$current_cell .= $processor->get_modifiable_text();
		}
	}

	if ( null !== $current_cell ) {
		$flush_cell();
	}
	if ( null !== $current_row ) {
		$rows[] = $current_row;
	}

	return $rows;
}
