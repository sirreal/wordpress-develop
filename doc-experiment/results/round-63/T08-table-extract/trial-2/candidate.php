<?php

declare(strict_types=1);

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
	$row         = null;
	$cell        = null;
	$current_tag = null;

	while ( $processor->next_token() && $processor->get_current_depth() >= $table_depth ) {
		$token_type = $processor->get_token_type();

		if ( '#text' === $token_type ) {
			if ( null !== $cell ) {
				$cell .= $processor->get_modifiable_text();
			}
			continue;
		}

		if ( '#tag' !== $token_type ) {
			continue;
		}

		$tag_name  = $processor->get_tag();
		$is_closer  = $processor->is_tag_closer();

		if ( 'TR' === $tag_name ) {
			if ( $is_closer ) {
				if ( null !== $cell ) {
					$row[] = $cell;
					$cell  = null;
				}
				if ( null !== $row ) {
					$rows[] = $row;
				}
				$row = null;
			} else {
				$row  = array();
				$cell = null;
			}
			continue;
		}

		if ( 'TD' !== $tag_name && 'TH' !== $tag_name ) {
			continue;
		}

		if ( $is_closer ) {
			if ( null !== $cell ) {
				$row[] = $cell;
				$cell  = null;
			}
			continue;
		}

		$cell = '';
	}

	if ( null !== $processor->get_last_error() ) {
		return array();
	}

	return $rows;
}
