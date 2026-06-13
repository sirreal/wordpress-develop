<?php
function table_to_array( string $html ): array {
    $processor = WP_HTML_Processor::create_fragment( $html );
    if ( null === $processor ) {
        return array();
    }

    if ( ! $processor->next_tag( 'TABLE' ) ) {
        return array();
    }

    $table_depth       = $processor->get_current_depth();
    $rows              = array();
    $current_row       = null;
    $current_cell_text = null;

    while ( $processor->next_token() ) {
        if ( $processor->get_current_depth() < $table_depth ) {
            break;
        }

        $token_type = $processor->get_token_type();
        $token_name = $processor->get_token_name();

        if ( '#tag' === $token_type ) {
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
                    if ( null !== $current_row && null !== $current_cell_text ) {
                        $current_row[] = $current_cell_text;
                    }
                    $current_cell_text = null;
                } else {
                    if ( null === $current_row ) {
                        $current_row = array();
                    }
                    $current_cell_text = '';
                }

                continue;
            }
        }

        if ( null !== $current_cell_text && '#text' === $token_type ) {
            $current_cell_text .= $processor->get_modifiable_text();
        }
    }

    if ( null !== $current_cell_text && null !== $current_row ) {
        $current_row[] = $current_cell_text;
    }

    if ( null !== $current_row ) {
        $rows[] = $current_row;
    }

    if ( $processor->paused_at_incomplete_token() || null !== $processor->get_last_error() ) {
        return array();
    }

    return $rows;
}
