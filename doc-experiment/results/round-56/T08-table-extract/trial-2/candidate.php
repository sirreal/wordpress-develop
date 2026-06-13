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
	$in_cell     = false;

	while ( $processor->next_token() ) {
		if ( $processor->get_current_depth() < $table_depth ) {
			break;
		}

		if ( '#text' === $processor->get_token_type() && $in_cell ) {
			$current_cell .= $processor->get_modifiable_text();
			continue;
		}

		if ( '#tag' !== $processor->get_token_type() ) {
			continue;
		}

		$tag_name = $processor->get_tag();
		if ( null === $tag_name ) {
			continue;
		}

		if ( 'TR' === $tag_name ) {
			if ( $processor->is_tag_closer() ) {
				if ( null !== $current_row ) {
					$rows[] = $current_row;
					$current_row = null;
				}
			} else {
				$current_row = array();
			}
			continue;
		}

		if ( 'TD' !== $tag_name && 'TH' !== $tag_name ) {
			continue;
		}

		if ( $processor->is_tag_closer() ) {
			if ( $in_cell && null !== $current_row ) {
				$current_row[] = $current_cell;
			}
			$current_cell = null;
			$in_cell      = false;
			continue;
		}

		if ( null === $current_row ) {
			$current_row = array();
		}

		$current_cell = '';
		$in_cell      = true;
	}

	return $rows;
}
