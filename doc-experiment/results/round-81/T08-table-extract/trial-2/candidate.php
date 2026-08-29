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
	$current_row  = null;
	$current_cell = null;
	$cell_open    = false;

	while ( $processor->next_token() ) {
		if ( $processor->get_current_depth() < $table_depth ) {
			break;
		}

		if ( '#text' === $processor->get_token_type() ) {
			if ( $cell_open ) {
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
				if ( null !== $current_row ) {
					if ( $cell_open ) {
						$current_row[] = $current_cell;
						$current_cell   = null;
						$cell_open      = false;
					}
					$rows[] = $current_row;
				}
				$current_row = null;
			}
			else {
				$current_row  = array();
				$current_cell = null;
				$cell_open    = false;
			}

			continue;
		}

		if ( 'TD' === $tag || 'TH' === $tag ) {
			if ( $processor->is_tag_closer() ) {
				if ( null !== $current_row ) {
					$current_row[] = (string) ( $current_cell ?? '' );
				}
				$current_cell = null;
				$cell_open    = false;
			}
			else {
				$current_cell = '';
				$cell_open    = true;
			}
		}
	}

	if ( $cell_open && null !== $current_row ) {
		$current_row[] = (string) $current_cell;
	}

	if ( null !== $current_row ) {
		$rows[] = $current_row;
	}

	return $rows;
}
