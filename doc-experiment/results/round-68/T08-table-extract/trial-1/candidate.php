<?php

declare( strict_types=1 );

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

	while ( $processor->next_token() && $processor->get_current_depth() >= $table_depth ) {
		$token_type = $processor->get_token_type();

		if ( '#tag' !== $token_type ) {
			if ( null !== $cell && '#text' === $token_type ) {
				$cell .= $processor->get_modifiable_text();
			}
			continue;
		}

		$tag_name = $processor->get_tag();
		if ( null === $tag_name ) {
			continue;
		}

		if ( ! $processor->is_tag_closer() ) {
			if ( 'TR' === $tag_name ) {
				$row = array();
			} elseif ( 'TD' === $tag_name || 'TH' === $tag_name ) {
				$cell = '';
			}
			continue;
		}

		if ( 'TD' === $tag_name || 'TH' === $tag_name ) {
			if ( null !== $row ) {
				$row[] = null === $cell ? '' : $cell;
			}
			$cell = null;
			continue;
		}

		if ( 'TR' === $tag_name ) {
			if ( null !== $row ) {
				$rows[] = $row;
			}
			$row = null;
		}
	}

	if ( null !== $row ) {
		$rows[] = $row;
	}

	return $rows;
}
