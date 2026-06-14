<?php

function table_to_array( string $html ): array {
	$processor = WP_HTML_Processor::create_fragment( $html );
	if ( null === $processor ) {
		return array();
	}

	if ( ! $processor->next_tag( array( 'tag_name' => 'table' ) ) ) {
		return array();
	}

	$table_depth = $processor->get_current_depth();
	$rows        = array();
	$current_row  = null;
	$current_cell = null;

	$flush_cell = static function () use ( &$current_row, &$current_cell ) : void {
		if ( null === $current_row || null === $current_cell ) {
			return;
		}

		$current_row[] = $current_cell;
		$current_cell   = null;
	};

	$flush_row = static function () use ( &$rows, &$current_row, &$current_cell, $flush_cell ) : void {
		$flush_cell();

		if ( null !== $current_row ) {
			$rows[] = $current_row;
		}

		$current_row = null;
	};

	while ( $processor->next_token() && $processor->get_current_depth() >= $table_depth ) {
		if ( '#tag' !== $processor->get_token_type() ) {
			if ( null !== $current_cell && '#text' === $processor->get_token_type() ) {
				$current_cell .= $processor->get_modifiable_text();
			}
			continue;
		}

		$tag_name = $processor->get_tag();
		if ( null === $tag_name ) {
			continue;
		}

		if ( 'TR' === $tag_name ) {
			if ( $processor->is_tag_closer() ) {
				$flush_row();
			} else {
				$current_row = array();
			}
			continue;
		}

		if ( 'TD' === $tag_name || 'TH' === $tag_name ) {
			if ( $processor->is_tag_closer() ) {
				$flush_cell();
			} else {
				$current_cell = '';
			}
		}
	}

	if ( null !== $current_cell || null !== $current_row ) {
		$flush_row();
	}

	return $rows;
}
