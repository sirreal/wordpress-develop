<?php
function table_to_array( string $html ): array {
	if ( ! class_exists( 'WP_HTML_Processor' ) ) {
		return array();
	}

	$processor = WP_HTML_Processor::create_fragment( $html );
	if ( null === $processor ) {
		return array();
	}

	$rows          = array();
	$current_row   = null;
	$current_cell  = null;
	$table_depth   = null;
	$row_depth     = null;
	$cell_depth    = null;

	while ( $processor->next_token() ) {
		if ( null === $table_depth ) {
			if ( '#tag' !== $processor->get_token_type() || $processor->is_tag_closer() ) {
				continue;
			}

			if ( 'TABLE' === $processor->get_tag() ) {
				$table_depth = $processor->get_current_depth();
			}

			continue;
		}

		if ( $processor->get_current_depth() < $table_depth ) {
			break;
		}

		if ( '#tag' === $processor->get_token_type() ) {
			$tag_name = $processor->get_tag();

			if ( 'TR' === $tag_name ) {
				if ( $processor->is_tag_closer() ) {
					if ( null !== $current_row ) {
						if ( null !== $current_cell ) {
							$current_row[] = $current_cell;
							$current_cell   = null;
						}
						$rows[]        = $current_row;
						$current_row   = null;
					}
					$row_depth = null;
				} else {
					$current_row = array();
					$row_depth   = $processor->get_current_depth();
				}
				continue;
			}

			if ( null !== $current_row && ( 'TD' === $tag_name || 'TH' === $tag_name ) ) {
				if ( $processor->is_tag_closer() ) {
					if ( null !== $current_cell ) {
						$current_row[] = $current_cell;
						$current_cell   = null;
					}
					$cell_depth = null;
				} else {
					$current_cell = '';
					$cell_depth   = $processor->get_current_depth();
				}
			}

			continue;
		}

		if ( null !== $current_cell && '#text' === $processor->get_token_type() && $processor->get_current_depth() > $cell_depth ) {
			$current_cell .= $processor->get_modifiable_text();
		}
	}

	if ( null !== $current_row ) {
		if ( null !== $current_cell ) {
			$current_row[] = $current_cell;
		}
		$rows[] = $current_row;
	}

	return $rows;
}
