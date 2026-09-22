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
	$cell_tag    = null;

	while ( $processor->next_token() ) {
		if ( $processor->get_current_depth() < $table_depth ) {
			break;
		}

		if ( '#text' === $processor->get_token_type() ) {
			if ( null !== $cell ) {
				$cell .= $processor->get_modifiable_text();
			}
			continue;
		}

		if ( '#tag' !== $processor->get_token_type() ) {
			continue;
		}

		$tag_name   = $processor->get_tag();
		$is_closer  = $processor->is_tag_closer();

		if ( ! $is_closer && 'TR' === $tag_name ) {
			$row = array();
			continue;
		}

		if ( ! $is_closer && ( 'TD' === $tag_name || 'TH' === $tag_name ) ) {
			$cell     = '';
			$cell_tag = $tag_name;
			continue;
		}

		if ( $is_closer && ( 'TD' === $tag_name || 'TH' === $tag_name ) ) {
			if ( null !== $cell ) {
				if ( null === $row ) {
					$row = array();
				}
				$row[] = $cell;
				$cell   = null;
				$cell_tag = null;
			}
			continue;
		}

		if ( $is_closer && 'TR' === $tag_name ) {
			if ( null !== $cell ) {
				if ( null === $row ) {
					$row = array();
				}
				$row[] = $cell;
				$cell   = null;
				$cell_tag = null;
			}
			if ( null !== $row ) {
				$rows[] = $row;
				$row    = null;
			}
			continue;
		}
	}

	if ( null !== $cell ) {
		if ( null === $row ) {
			$row = array();
		}
		$row[] = $cell;
	}

	if ( null !== $row ) {
		$rows[] = $row;
	}

	return $rows;
}
