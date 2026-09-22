<?php

function table_to_array( string $html ): array {
    $processor = WP_HTML_Processor::create_fragment( $html );
    if ( null === $processor ) {
        return array();
    }

    if ( ! $processor->next_tag( 'TABLE' ) ) {
        return array();
    }

    $table_depth  = $processor->get_current_depth();
    $rows         = array();
    $current_row  = null;
    $current_cell = null;

    while ( $processor->next_token() ) {
        if ( $processor->get_current_depth() < $table_depth ) {
            break;
        }

        $token_type = $processor->get_token_type();
        $token_name = $processor->get_token_name();

        if ( 'TR' === $token_name ) {
            if ( $processor->is_tag_closer() ) {
                if ( null !== $current_row ) {
                    $rows[] = $current_row;
                    $current_row = null;
                }
            } else {
                $current_row = array();
            }

            continue;
        }

        if ( 'TD' === $token_name || 'TH' === $token_name ) {
            if ( $processor->is_tag_closer() ) {
                if ( null !== $current_row && null !== $current_cell ) {
                    $current_row[] = $current_cell;
                }
                $current_cell = null;
            } else {
                if ( null === $current_row ) {
                    $current_row = array();
                }
                $current_cell = '';
            }

            continue;
        }

        if ( null === $current_cell ) {
            continue;
        }

        if ( '#text' === $token_type ) {
            $current_cell .= $processor->get_modifiable_text();
            continue;
        }

        if (
            ! $processor->is_tag_closer() &&
            in_array( $token_name, array( 'SCRIPT', 'STYLE', 'TEXTAREA', 'TITLE' ), true )
        ) {
            $current_cell .= $processor->get_modifiable_text();
        }
    }

    return $rows;
}
