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
	$row         = null;
	$cell        = null;

	$flush_cell = static function () use ( &$cell, &$row ): void {
		if ( null === $cell ) {
			return;
		}

		if ( null === $row ) {
			$row = array();
		}

		$row[] = $cell;
		$cell  = null;
	};

	$flush_row = static function () use ( &$flush_cell, &$row, &$rows ): void {
		$flush_cell();

		if ( null !== $row ) {
			$rows[] = $row;
			$row    = null;
		}
	};

	while ( $processor->next_token() && $processor->get_current_depth() >= $table_depth ) {
		if ( '#text' === $processor->get_token_type() ) {
			if ( null !== $cell ) {
				$cell .= $processor->get_modifiable_text();
			}
			continue;
		}

		if ( '#tag' !== $processor->get_token_type() ) {
			continue;
		}

		$tag = $processor->get_token_name();
		if ( 'TR' === $tag ) {
			if ( $processor->is_tag_closer() ) {
				$flush_row();
			} else {
				$flush_row();
				$row = array();
			}
			continue;
		}

		if ( 'TD' === $tag || 'TH' === $tag ) {
			if ( $processor->is_tag_closer() ) {
				$flush_cell();
			} else {
				$flush_cell();
				$cell = '';
			}
		}
	}

	$flush_row();

	if ( method_exists( $processor, 'get_last_error' ) && null !== $processor->get_last_error() ) {
		return $rows;
	}

	return $rows;
}
