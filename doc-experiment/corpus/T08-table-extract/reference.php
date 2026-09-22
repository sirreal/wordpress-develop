<?php

function table_to_array( string $html ): array {
	$processor = WP_HTML_Processor::create_fragment( $html );
	if ( null === $processor || ! $processor->next_tag( 'TABLE' ) ) {
		return array();
	}

	$table_depth = $processor->get_current_depth();
	$rows        = array();
	$row         = null;
	$cell        = null;

	while ( $processor->next_token() && $processor->get_current_depth() >= $table_depth ) {
		$token_name = $processor->get_token_name();

		if ( '#text' === $token_name ) {
			if ( null !== $cell ) {
				$cell .= $processor->get_modifiable_text();
			}
			continue;
		}

		$is_closer = $processor->is_tag_closer();

		switch ( $token_name ) {
			case 'TR':
				if ( $is_closer ) {
					if ( null !== $row ) {
						$rows[] = $row;
						$row    = null;
					}
				} else {
					$row = array();
				}
				break;

			case 'TD':
			case 'TH':
				if ( $is_closer ) {
					if ( null !== $row && null !== $cell ) {
						$row[] = $cell;
					}
					$cell = null;
				} else {
					$cell = '';
				}
				break;
		}
	}

	return $rows;
}
