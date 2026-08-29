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

	while ( $processor->next_token() && $processor->get_current_depth() >= $table_depth ) {
		if ( '#tag' !== $processor->get_token_type() ) {
			if ( null !== $cell && '#text' === $processor->get_token_type() ) {
				$cell .= $processor->get_modifiable_text();
			}
			continue;
		}

		$tag = $processor->get_tag();
		if ( null === $tag ) {
			continue;
		}

		if ( 'TR' === $tag ) {
			if ( $processor->is_tag_closer() ) {
				if ( null !== $row ) {
					$rows[] = $row;
					$row    = null;
				}
				continue;
			}

			$row = array();
			continue;
		}

		if ( 'TD' === $tag || 'TH' === $tag ) {
			if ( $processor->is_tag_closer() ) {
				if ( null !== $row ) {
					$row[] = null !== $cell ? $cell : '';
				}
				$cell = null;
				continue;
			}

			$cell = '';
			continue;
		}

		if ( null !== $cell && ! $processor->is_tag_closer() && $processor->get_token_type() === '#tag' ) {
			continue;
		}
	}

	if ( null !== $row ) {
		$rows[] = $row;
	}

	return $rows;
}
