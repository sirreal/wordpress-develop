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
    $inside_cell   = false;

    while ( $processor->next_token() && $processor->get_current_depth() >= $table_depth ) {
        if ( '#tag' === $processor->get_token_type() ) {
            $tag_name = $processor->get_tag();

            if ( 'TR' === $tag_name ) {
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

            if ( 'TD' === $tag_name || 'TH' === $tag_name ) {
                if ( $processor->is_tag_closer() ) {
                    if ( null !== $current_row && null !== $current_cell ) {
                        $current_row[] = $current_cell;
                    }
                    $current_cell = null;
                    $inside_cell  = false;
                } else {
                    $current_cell = '';
                    $inside_cell  = true;
                }

                continue;
            }
        }

        if ( $inside_cell && '#text' === $processor->get_token_type() ) {
            $current_cell .= $processor->get_modifiable_text();
        }
    }

    if ( $processor->paused_at_incomplete_token() || null !== $processor->get_last_error() ) {
        return array();
    }

    return $rows;
}
