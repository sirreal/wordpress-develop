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

		while ( $processor->next_token() ) {
			if ( $processor->get_current_depth() < $table_depth ) {
				break;
			}

			if ( '#tag' !== $processor->get_token_type() ) {
				if ( null !== $current_cell && '#text' === $processor->get_token_type() ) {
					$current_cell .= $processor->get_modifiable_text();
				}
				continue;
			}

			$tag = $processor->get_tag();

			if ( 'TR' === $tag ) {
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

			if ( 'TD' === $tag || 'TH' === $tag ) {
				if ( $processor->is_tag_closer() ) {
					if ( null !== $current_row ) {
						$current_row[] = null === $current_cell ? '' : $current_cell;
					}
					$current_cell = null;
				} else {
					$current_cell = '';
				}
			}
		}

		return $rows;
	}

	return array();
}
