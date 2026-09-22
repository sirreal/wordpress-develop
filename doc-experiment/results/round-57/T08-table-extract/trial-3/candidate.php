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

	$handle_token = static function () use ( $processor, &$rows, &$current_row, &$current_cell ) : void {
		if ( '#text' === $processor->get_token_type() ) {
			if ( null !== $current_cell ) {
				$current_cell .= $processor->get_modifiable_text();
			}
			return;
		}

		if ( '#tag' !== $processor->get_token_type() ) {
			return;
		}

		$tag = $processor->get_tag();
		if ( null === $tag ) {
			return;
		}

		if ( 'TR' === $tag ) {
			if ( $processor->is_tag_closer() ) {
				if ( null !== $current_row ) {
					if ( null !== $current_cell ) {
						$current_row[] = $current_cell;
						$current_cell = null;
					}
					$rows[] = $current_row;
					$current_row = null;
				}
			} else {
				$current_row  = array();
				$current_cell = null;
			}
			return;
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
	};

	$handle_token();

	while ( $processor->next_token() ) {
		if ( $processor->get_current_depth() < $table_depth ) {
			break;
		}

		$handle_token();
	}

	if ( null !== $current_cell ) {
		if ( null !== $current_row ) {
			$current_row[] = $current_cell;
		}
	}

	if ( null !== $current_row ) {
		$rows[] = $current_row;
	}

	return $rows;
}
