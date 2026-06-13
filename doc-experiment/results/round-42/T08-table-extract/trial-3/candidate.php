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
    $current_cell = '';
    $in_cell      = false;

    while ( $processor->next_token() && $processor->get_current_depth() >= $table_depth ) {
        $token_type = $processor->get_token_type();

        if ( '#text' === $token_type ) {
            if ( $in_cell ) {
                $current_cell .= $processor->get_modifiable_text();
            }
            continue;
        }

        if ( '#tag' !== $token_type ) {
            continue;
        }

        $tag = $processor->get_tag();
        if ( null === $tag ) {
            continue;
        }

        if ( $processor->is_tag_closer() ) {
            if ( 'TD' === $tag || 'TH' === $tag ) {
                if ( null === $current_row ) {
                    $current_row = array();
                }
                $current_row[] = $current_cell;
                $current_cell  = '';
                $in_cell       = false;
            } elseif ( 'TR' === $tag ) {
                if ( null !== $current_row ) {
                    $rows[]      = $current_row;
                    $current_row = null;
                }
            }

            continue;
        }

        if ( 'TR' === $tag ) {
            $current_row = array();
            continue;
        }

        if ( 'TD' === $tag || 'TH' === $tag ) {
            if ( null === $current_row ) {
                $current_row = array();
            }
            $current_cell = '';
            $in_cell      = true;
            continue;
        }

        if ( $in_cell && ( 'SCRIPT' === $tag || 'STYLE' === $tag || 'TEXTAREA' === $tag || 'TITLE' === $tag ) ) {
            $current_cell .= $processor->get_modifiable_text();
        }
    }

    if ( null !== $processor->get_last_error() ) {
        return array();
    }

    if ( $in_cell ) {
        if ( null === $current_row ) {
            $current_row = array();
        }
        $current_row[] = $current_cell;
    }

    if ( null !== $current_row ) {
        $rows[] = $current_row;
    }

    return $rows;
}
