<?php

function table_to_array( string $html ): array {
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

	while ( $processor->next_token() && $processor->get_current_depth() >= $table_depth ) {
		$token_type = $processor->get_token_type();
		$tag_name   = $processor->get_tag();
		$is_closer  = $processor->is_tag_closer();

		if ( '#tag' === $token_type && 'TR' === $tag_name ) {
			if ( $is_closer ) {
				if ( null !== $current_cell ) {
					$current_row[] = $current_cell;
					$current_cell   = null;
				}
				if ( null !== $current_row ) {
					$rows[] = $current_row;
					$current_row = null;
				}
			} else {
				$current_row  = array();
				$current_cell = null;
			}
			continue;
		}

		if ( '#tag' === $token_type && ( 'TD' === $tag_name || 'TH' === $tag_name ) ) {
			if ( $is_closer ) {
				if ( null !== $current_cell ) {
					if ( null === $current_row ) {
						$current_row = array();
					}
					$current_row[] = $current_cell;
					$current_cell   = null;
				}
			} else {
				$current_cell = '';
			}
			continue;
		}

		if ( null !== $current_cell && '#text' === $token_type ) {
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
