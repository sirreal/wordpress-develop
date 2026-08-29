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
    $cell_depth  = null;

    while ( $processor->next_token() ) {
        if ( $processor->get_current_depth() < $table_depth ) {
            break;
        }

        if ( '#tag' === $processor->get_token_type() ) {
            $tag_name = $processor->get_tag();

            if ( 'TR' === $tag_name ) {
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

            if ( 'TD' === $tag_name || 'TH' === $tag_name ) {
                if ( $processor->is_tag_closer() ) {
                    $cell_depth = null;
                } else {
                    if ( null === $current_row ) {
                        $current_row = array();
                    }

                    $current_row[] = '';
                    $cell_depth    = $processor->get_current_depth();
                }

                continue;
            }
        }

        if (
            null !== $cell_depth &&
            '#text' === $processor->get_token_type() &&
            $processor->get_current_depth() >= $cell_depth
        ) {
            $current_row[ count( $current_row ) - 1 ] .= $processor->get_modifiable_text();
        }
    }

    if ( null !== $current_row ) {
        $rows[] = $current_row;
    }

    if ( $processor->paused_at_incomplete_token() || null !== $processor->get_last_error() ) {
        return array();
    }

    return $rows;
}
