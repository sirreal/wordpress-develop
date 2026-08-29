<?php

function table_to_array( string $html ): array {
    $processor = WP_HTML_Processor::create_fragment( $html );
    if ( null === $processor ) {
        return array();
    }

    if ( ! $processor->next_tag( 'TABLE' ) ) {
        return array();
    }

    if ( null !== $processor->get_last_error() ) {
        return array();
    }

    $table_depth  = $processor->get_current_depth();
    $rows         = array();
    $current_row  = null;
    $current_cell = '';
    $inside_cell  = false;
    $closed_table = false;

    while ( $processor->next_token() ) {
        if ( $processor->get_current_depth() < $table_depth ) {
            $closed_table = true;
            break;
        }

        $token_type = $processor->get_token_type();

        if ( '#tag' === $token_type ) {
            $tag_name = $processor->get_tag();

            if ( $processor->is_tag_closer() ) {
                if ( ( 'TD' === $tag_name || 'TH' === $tag_name ) && $inside_cell ) {
                    if ( null === $current_row ) {
                        $current_row = array();
                    }
                    $current_row[] = $current_cell;
                    $current_cell  = '';
                    $inside_cell   = false;
                } elseif ( 'TR' === $tag_name && null !== $current_row ) {
                    $rows[]      = $current_row;
                    $current_row = null;
                }

                continue;
            }

            if ( 'TR' === $tag_name ) {
                $current_row = array();
                continue;
            }

            if ( 'TD' === $tag_name || 'TH' === $tag_name ) {
                if ( null === $current_row ) {
                    $current_row = array();
                }
                $current_cell = '';
                $inside_cell  = true;
                continue;
            }

            if (
                $inside_cell &&
                ( 'SCRIPT' === $tag_name || 'STYLE' === $tag_name || 'TEXTAREA' === $tag_name || 'TITLE' === $tag_name )
            ) {
                $current_cell .= $processor->get_modifiable_text();
            }

            continue;
        }

        if ( $inside_cell && ( '#text' === $token_type || '#cdata-section' === $token_type ) ) {
            $current_cell .= $processor->get_modifiable_text();
        }
    }

    if ( ! $closed_table && ( null !== $processor->get_last_error() || $processor->paused_at_incomplete_token() ) ) {
        return array();
    }

    return $rows;
}
