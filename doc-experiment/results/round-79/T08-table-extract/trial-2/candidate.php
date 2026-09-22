<?php
function table_to_array( string $html ): array {
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
		$token_name = $processor->get_token_name();

		if ( '#text' === $token_type ) {
			if ( null !== $cell ) {
				$cell .= $processor->get_modifiable_text();
			}
			continue;
		}

		if ( '#tag' !== $token_type || null === $token_name ) {
			continue;
		}

		if ( 'TR' === $token_name ) {
			if ( $processor->is_tag_closer() ) {
				if ( null !== $cell ) {
					$row[] = $cell;
					$cell  = null;
				}
				if ( null !== $row ) {
					$rows[] = $row;
					$row    = null;
				}
			} else {
				$row  = array();
				$cell = null;
			}
			continue;
		}

		if ( 'TD' === $token_name || 'TH' === $token_name ) {
			if ( $processor->is_tag_closer() ) {
				if ( null !== $cell ) {
					if ( null === $row ) {
						$row = array();
					}
					$row[] = $cell;
					$cell  = null;
				}
			} else {
				if ( null === $row ) {
					$row = array();
				}
				$cell = '';
			}
		}
	}

	return $rows;
}
