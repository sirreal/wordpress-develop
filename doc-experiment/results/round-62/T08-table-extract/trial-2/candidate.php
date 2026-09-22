<?php

function table_to_array( string $html ): array {
	if ( ! class_exists( 'WP_HTML_Processor' ) ) {
		return array();
	}

	$processor = WP_HTML_Processor::create_fragment( $html );
	if ( null === $processor ) {
		return array();
	}

	if ( ! $processor->next_tag( 'table' ) ) {
		return array();
	}

	$table_depth = $processor->get_current_depth();
	$rows        = array();
	$current_row = null;
	$current_cell = null;
	$collecting_cell = false;

	$flush_cell = static function () use ( &$current_row, &$current_cell, &$collecting_cell ): void {
		if ( ! $collecting_cell ) {
			return;
		}

		if ( null === $current_row ) {
			$current_row = array();
		}

		$current_row[]   = $current_cell ?? '';
		$current_cell    = null;
		$collecting_cell = false;
	};

	$flush_row = static function () use ( &$rows, &$current_row, $flush_cell ): void {
		$flush_cell();

		if ( null !== $current_row ) {
			$rows[] = $current_row;
			$current_row = null;
		}
	};

	while ( $processor->next_token() ) {
		if ( $processor->get_current_depth() < $table_depth ) {
			break;
		}

		if ( '#text' === $processor->get_token_type() ) {
			if ( $collecting_cell ) {
				$current_cell .= $processor->get_modifiable_text();
			}
			continue;
		}

		if ( '#tag' !== $processor->get_token_type() ) {
			continue;
		}

		$tag = $processor->get_tag();
		if ( null === $tag ) {
			continue;
		}

		if ( 'TR' === $tag ) {
			if ( $processor->is_tag_closer() ) {
				$flush_row();
			} else {
				$flush_row();
				$current_row = array();
			}
			continue;
		}

		if ( 'TD' !== $tag && 'TH' !== $tag ) {
			continue;
		}

		if ( $processor->is_tag_closer() ) {
			$flush_cell();
			continue;
		}

		$flush_cell();
		$collecting_cell = true;
		$current_cell    = '';
		if ( null === $current_row ) {
			$current_row = array();
		}
	}

	$flush_row();

	return $rows;
}
