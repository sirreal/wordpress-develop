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
    $in_cell       = false;

    while ( $processor->next_token() ) {
        if ( $processor->get_current_depth() < $table_depth ) {
            break;
        }

        if ( '#tag' === $processor->get_token_type() ) {
            $tag = $processor->get_tag();

            if ( 'TR' === $tag ) {
                if ( $processor->is_tag_closer() ) {
                    if ( is_array( $current_row ) ) {
                        $rows[] = $current_row;
                        $current_row = null;
                    }
                } else {
                    $current_row = array();
                }

                continue;
            }

            if ( 'TD' === $tag || 'TH' === $tag ) {
                if ( $processor->is_tag_closer() ) {
                    if ( $in_cell && is_array( $current_row ) ) {
                        $current_row[] = $current_cell;
                    }
                    $current_cell = null;
                    $in_cell      = false;
                } else {
                    $current_cell = '';
                    $in_cell      = true;
                }

                continue;
            }
        }

        if ( $in_cell && '#text' === $processor->get_token_type() ) {
            $current_cell .= $processor->get_modifiable_text();
        }
    }

    if ( null !== $processor->get_last_error() ) {
        return array();
    }

    return $rows;
}
