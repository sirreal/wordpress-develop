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
			if ( '#text' === $processor->get_token_type() ) {
				if ( null !== $current_cell ) {
					$current_cell .= $processor->get_modifiable_text();
				}
				continue;
			}

			if ( '#tag' !== $processor->get_token_type() ) {
				continue;
			}

			$tag = $processor->get_tag();
			if ( 'TR' === $tag ) {
				if ( $processor->is_tag_closer() ) {
					if ( null !== $current_cell ) {
						$current_row[] = $current_cell;
						$current_cell  = null;
					}

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
					if ( null !== $current_cell ) {
						if ( null === $current_row ) {
							$current_row = array();
						}
						$current_row[] = $current_cell;
						$current_cell  = null;
					}
				} else {
					if ( null === $current_row ) {
						$current_row = array();
					}
					$current_cell = '';
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

	return array();
}
