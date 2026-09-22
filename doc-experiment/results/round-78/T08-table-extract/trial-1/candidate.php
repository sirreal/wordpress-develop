<?php

function table_to_array( string $html ): array {
	if ( ! class_exists( 'WP_HTML_Processor' ) ) {
		return array();
	}

	$processor = WP_HTML_Processor::create_fragment( $html );
	if ( null === $processor ) {
		return array();
	}

	$table_depth = null;
	$rows        = array();
	$current_row = null;
	$current_cell = null;
	$cell_tag    = null;

	while ( $processor->next_token() ) {
		if ( null === $table_depth ) {
			if ( '#tag' !== $processor->get_token_type() || $processor->is_tag_closer() || 'TABLE' !== $processor->get_token_name() ) {
				continue;
			}

			$table_depth = $processor->get_current_depth();
			continue;
		}

		if ( $processor->get_current_depth() < $table_depth ) {
			break;
		}

		if ( '#tag' === $processor->get_token_type() ) {
			$tag = $processor->get_token_name();

			if ( 'TR' === $tag ) {
				if ( $processor->is_tag_closer() ) {
					if ( null !== $current_row ) {
						if ( null !== $current_cell ) {
							$current_row[] = $current_cell;
							$current_cell   = null;
							$cell_tag       = null;
						}
						$rows[] = $current_row;
						$current_row = null;
					}
				} else {
					$current_row  = array();
					$current_cell  = null;
					$cell_tag      = null;
				}
				continue;
			}

			if ( 'TD' === $tag || 'TH' === $tag ) {
				if ( $processor->is_tag_closer() ) {
					if ( null !== $current_cell && null !== $current_row ) {
						$current_row[] = $current_cell;
					}
					$current_cell = null;
					$cell_tag     = null;
				} else {
					$current_cell = '';
					$cell_tag     = $tag;
				}
				continue;
			}
		}

		if ( null !== $current_cell && '#text' === $processor->get_token_type() ) {
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
