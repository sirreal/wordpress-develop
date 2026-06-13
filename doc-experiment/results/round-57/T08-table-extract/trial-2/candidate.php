<?php

function table_to_array( string $html ): array {
	if ( ! class_exists( 'WP_HTML_Processor' ) ) {
		return array();
	}

	$processor = WP_HTML_Processor::create_fragment( $html );
	if ( null === $processor ) {
		return array();
	}

	$found_table = false;
	$table_depth = null;

	while ( $processor->next_token() ) {
		if ( '#tag' !== $processor->get_token_type() ) {
			continue;
		}

		if ( 'TABLE' === $processor->get_tag() && ! $processor->is_tag_closer() ) {
			$found_table = true;
			$table_depth = $processor->get_current_depth();
			break;
		}
	}

	if ( ! $found_table ) {
		return array();
	}

	$rows        = array();
	$current_row = null;
	$current_cell = null;

	while ( $processor->next_token() ) {
		if ( $processor->get_current_depth() < $table_depth ) {
			break;
		}

		$token_type = $processor->get_token_type();

		if ( '#text' === $token_type ) {
			if ( null !== $current_cell ) {
				$current_cell .= $processor->get_modifiable_text();
			}
			continue;
		}

		if ( '#tag' !== $token_type ) {
			continue;
		}

		$tag_name = $processor->get_tag();
		$is_closer = $processor->is_tag_closer();

		if ( 'TR' === $tag_name ) {
			if ( ! $is_closer ) {
				$current_row  = array();
				$current_cell = null;
			} else {
				if ( null !== $current_row ) {
					$rows[] = $current_row;
					$current_row = null;
				}
				$current_cell = null;
			}
			continue;
		}

		if ( 'TD' !== $tag_name && 'TH' !== $tag_name ) {
			continue;
		}

		if ( ! $is_closer ) {
			if ( null === $current_row ) {
				$current_row = array();
			}
			$current_cell = '';
			continue;
		}

		if ( null !== $current_cell ) {
			$current_row[] = $current_cell;
			$current_cell = null;
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
