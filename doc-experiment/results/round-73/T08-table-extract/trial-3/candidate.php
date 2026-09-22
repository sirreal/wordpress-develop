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
	$cell_text   = null;

	while ( $processor->next_token() && $processor->get_current_depth() >= $table_depth ) {
		if ( '#tag' !== $processor->get_token_type() ) {
			if ( null !== $cell_text && '#text' === $processor->get_token_type() ) {
				$cell_text .= $processor->get_modifiable_text();
			}
			continue;
		}

		$tag = $processor->get_tag();
		if ( null === $tag ) {
			continue;
		}

		if ( 'TR' === $tag ) {
			if ( $processor->is_tag_closer() ) {
				if ( null !== $cell_text ) {
					$row[] = $cell_text;
					$cell_text = null;
				}

				if ( null !== $row ) {
					$rows[] = $row;
					$row    = null;
				}
			} elseif ( null === $row ) {
				$row = array();
			}

			continue;
		}

		if ( 'TD' === $tag || 'TH' === $tag ) {
			if ( $processor->is_tag_closer() ) {
				if ( null !== $cell_text ) {
					$row[] = $cell_text;
					$cell_text = null;
				}
			} else {
				if ( null === $row ) {
					$row = array();
				}
				$cell_text = '';
			}
		}
	}

	if ( null !== $cell_text ) {
		$row[] = $cell_text;
	}

	if ( null !== $row ) {
		$rows[] = $row;
	}

	return $rows;
}
