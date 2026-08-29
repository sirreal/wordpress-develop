<?php

function table_to_array( string $html ): array {
    $processor = WP_HTML_Processor::create_fragment( $html );
    if ( null === $processor ) {
        return array();
    }

    if ( ! $processor->next_tag( 'TABLE' ) ) {
        return array();
    }

    $table_depth   = $processor->get_current_depth();
    $rows          = array();
    $current_row   = null;
    $current_cell  = null;
    $cell_depth    = null;

    while ( $processor->next_token() ) {
        if ( $processor->get_current_depth() < $table_depth ) {
            break;
        }

        $token_type = $processor->get_token_type();
        $token_name = $processor->get_token_name();

        if ( '#tag' === $token_type ) {
            if ( 'TR' === $token_name ) {
                if ( $processor->is_tag_closer() ) {
                    if ( null !== $current_cell ) {
                        $current_row[] = $current_cell;
                        $current_cell  = null;
                        $cell_depth    = null;
                    }

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
                    if ( null !== $current_cell ) {
                        $current_row[] = $current_cell;
                        $current_cell  = null;
                        $cell_depth    = null;
                    }
                } else {
                    $current_cell = '';
                    $cell_depth   = $processor->get_current_depth();
                }

                continue;
            }

            if (
                null !== $current_cell &&
                ! $processor->is_tag_closer() &&
                in_array( $token_name, array( 'SCRIPT', 'STYLE', 'TEXTAREA', 'TITLE' ), true ) &&
                $processor->get_current_depth() > $cell_depth
            ) {
                $current_cell .= $processor->get_modifiable_text();
            }

            continue;
        }

        if (
            null !== $current_cell &&
            '#text' === $token_type &&
            null !== $cell_depth &&
            $processor->get_current_depth() > $cell_depth
        ) {
            $current_cell .= $processor->get_modifiable_text();
        }
    }

    if ( null !== $current_cell && null !== $current_row ) {
        $current_row[] = $current_cell;
    }

    if ( null !== $current_row ) {
        $rows[] = $current_row;
    }

    if ( $processor->paused_at_incomplete_token() || null !== $processor->get_last_error() ) {
        return array();
    }

    return $rows;
}
