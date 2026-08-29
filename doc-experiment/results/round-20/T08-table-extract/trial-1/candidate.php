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
    $current_row = null;
    $cell_text   = null;

    while ( $processor->next_token() && $processor->get_current_depth() >= $table_depth ) {
        $token_name = $processor->get_token_name();

        if ( 'TR' === $token_name ) {
            if ( $processor->is_tag_closer() ) {
                if ( null !== $current_row ) {
                    $rows[]      = $current_row;
                    $current_row = null;
                }
            } else {
                $current_row = array();
            }

            continue;
        }

        if ( 'TD' === $token_name || 'TH' === $token_name ) {
            if ( $processor->is_tag_closer() ) {
                if ( null !== $current_row && null !== $cell_text ) {
                    $current_row[] = $cell_text;
                }
                $cell_text = null;
            } else {
                $cell_text = '';
            }

            continue;
        }

        if ( null === $cell_text ) {
            continue;
        }

        if ( '#text' === $processor->get_token_type() ) {
            $cell_text .= $processor->get_modifiable_text();
            continue;
        }

        if ( '#tag' === $processor->get_token_type() && ! $processor->is_tag_closer() ) {
            $cell_text .= $processor->get_modifiable_text();
        }
    }

    return $rows;
}
