<?php

declare( strict_types=1 );

function table_to_array( string $html ): array {
	if ( '' === $html ) {
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

	$flush_cell = static function () use ( &$current_row, &$current_cell ): void {
		if ( null === $current_cell ) {
			return;
		}

		if ( null === $current_row ) {
			$current_row = array();
		}

		$current_row[] = $current_cell;
		$current_cell   = null;
	};

	$flush_row = static function () use ( &$rows, &$current_row ): void {
		if ( null === $current_row ) {
			return;
		}

		$rows[]      = $current_row;
		$current_row = null;
	};

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
		if ( 'TD' !== $tag && 'TH' !== $tag && 'TR' !== $tag ) {
			continue;
		}

		if ( ( 'TD' === $tag || 'TH' === $tag ) && ! $processor->is_tag_closer() ) {
			$current_cell = '';
			continue;
		}

		if ( ( 'TD' === $tag || 'TH' === $tag ) && $processor->is_tag_closer() ) {
			$flush_cell();
			continue;
		}

		if ( 'TR' === $tag && ! $processor->is_tag_closer() ) {
			$current_row  = array();
			$current_cell = null;
			continue;
		}

		if ( 'TR' === $tag && $processor->is_tag_closer() ) {
			$flush_cell();
			$flush_row();
		}
	}

	return $rows;
}
