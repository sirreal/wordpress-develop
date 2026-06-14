<?php

function table_to_array( string $html ): array {
	if ( ! class_exists( '\WP_HTML_Processor' ) ) {
		return array();
	}

	$processor = \WP_HTML_Processor::create_fragment( $html );
	if ( null === $processor ) {
		return array();
	}

	$found_table   = false;
	$table_depth   = null;
	$rows          = array();
	$current_row   = null;
	$current_cell  = null;

	while ( $processor->next_token() ) {
		if ( ! $found_table ) {
			if ( '#tag' !== $processor->get_token_type() || $processor->is_tag_closer() ) {
				continue;
			}

			if ( 'TABLE' !== $processor->get_tag() ) {
				continue;
			}

			$found_table = true;
			$table_depth = $processor->get_current_depth();
			continue;
		}

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

		$tag_name   = $processor->get_tag();
		$is_closer   = $processor->is_tag_closer();

		if ( ! $is_closer && 'TR' === $tag_name ) {
			if ( null !== $current_cell ) {
				$current_row[] = $current_cell;
				$current_cell  = null;
			}

			if ( null !== $current_row ) {
				$rows[] = $current_row;
			}

			$current_row  = array();
			$current_cell = null;
			continue;
		}

		if ( ! $is_closer && ( 'TD' === $tag_name || 'TH' === $tag_name ) ) {
			if ( null === $current_row ) {
				$current_row = array();
			}

			if ( null !== $current_cell ) {
				$current_row[] = $current_cell;
			}

			$current_cell = '';
			continue;
		}

		if ( $is_closer && ( 'TD' === $tag_name || 'TH' === $tag_name ) ) {
			if ( null !== $current_cell ) {
				$current_row[] = $current_cell;
				$current_cell  = null;
			}
			continue;
		}

		if ( $is_closer && 'TR' === $tag_name ) {
			if ( null !== $current_cell ) {
				$current_row[] = $current_cell;
				$current_cell  = null;
			}

			if ( null !== $current_row ) {
				$rows[] = $current_row;
				$current_row = null;
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
