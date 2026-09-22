<?php
function table_to_array( string $html ): array {
	if ( ! class_exists( 'WP_HTML_Processor' ) ) {
		return array();
	}

	$processor = WP_HTML_Processor::create_fragment( $html );
	if ( null === $processor ) {
		return array();
	}

	$rows              = array();
	$current_row       = null;
	$current_cell      = null;
	$in_table          = false;
	$table_depth       = null;

	while ( $processor->next_token() ) {
		$token_type = $processor->get_token_type();

		if ( ! $in_table ) {
			if ( '#tag' === $token_type && ! $processor->is_tag_closer() && 'TABLE' === $processor->get_tag() ) {
				$in_table    = true;
				$table_depth = $processor->get_current_depth();
			}
			continue;
		}

		if ( $processor->get_current_depth() < $table_depth ) {
			break;
		}

		if ( '#tag' === $token_type ) {
			$tag_name   = $processor->get_tag();
			$is_closer  = $processor->is_tag_closer();

			if ( 'TR' === $tag_name ) {
				if ( $is_closer ) {
					if ( null !== $current_cell ) {
						if ( null === $current_row ) {
							$current_row = array();
						}
						$current_row[] = $current_cell;
						$current_cell   = null;
					}

					if ( null !== $current_row ) {
						$rows[]       = $current_row;
						$current_row  = null;
					}
				} else {
					if ( null !== $current_row ) {
						$rows[]      = $current_row;
					}
					$current_row  = array();
					$current_cell = null;
				}

				continue;
			}

			if ( 'TD' === $tag_name || 'TH' === $tag_name ) {
				if ( $is_closer ) {
					if ( null !== $current_cell ) {
						if ( null === $current_row ) {
							$current_row = array();
						}
						$current_row[] = $current_cell;
					}
					$current_cell = null;
				} else {
					if ( null === $current_row ) {
						$current_row = array();
					}
					if ( null !== $current_cell ) {
						$current_row[] = $current_cell;
					}
					$current_cell = '';
				}

				continue;
			}
		}

		if ( '#text' === $token_type && null !== $current_cell ) {
			$current_cell .= $processor->get_modifiable_text();
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
