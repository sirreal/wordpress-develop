<?php

function table_to_array( string $html ): array {
	if ( ! class_exists( 'WP_HTML_Processor' ) ) {
		return array();
	}

	$processor = WP_HTML_Processor::create_fragment( $html );
	if ( null === $processor ) {
		return array();
	}

	while ( $processor->next_tag( 'TABLE' ) ) {
		if ( $processor->is_tag_closer() ) {
			continue;
		}

		$table_depth = $processor->get_current_depth();
		$rows        = array();
		$current_row = null;
		$current_cell = null;

		while ( $processor->next_token() && $processor->get_current_depth() >= $table_depth ) {
			$token_type = $processor->get_token_type();
			$token_name = $processor->get_token_name();

			if ( '#tag' === $token_type ) {
				if ( 'TR' === $token_name ) {
					if ( $processor->is_tag_closer() ) {
						if ( null !== $current_cell ) {
							$current_row[] = $current_cell;
							$current_cell = null;
						}
						if ( null !== $current_row ) {
							$rows[] = $current_row;
							$current_row = null;
						}
					} else {
						$current_row = array();
					}
				} elseif ( 'TD' === $token_name || 'TH' === $token_name ) {
					if ( $processor->is_tag_closer() ) {
						if ( null !== $current_cell && null !== $current_row ) {
							$current_row[] = $current_cell;
						}
						$current_cell = null;
					} else {
						$current_cell = '';
					}
				}
			} elseif ( '#text' === $token_type && null !== $current_cell ) {
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

		if ( null !== $processor->get_last_error() ) {
			return array();
		}

		return $rows;
	}

	return array();
}
