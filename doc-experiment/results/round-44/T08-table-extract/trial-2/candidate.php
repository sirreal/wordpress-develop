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
    $current_cell = null;

    while ( $processor->next_token() ) {
        if ( $processor->get_current_depth() < $table_depth ) {
            break;
        }

        $token_type = $processor->get_token_type();
        $tag_name   = $processor->get_tag();

        if ( '#tag' === $token_type ) {
            if ( ! $processor->is_tag_closer() ) {
                if ( 'TR' === $tag_name ) {
                    $current_row = array();
                } elseif ( null !== $current_row && ( 'TD' === $tag_name || 'TH' === $tag_name ) ) {
                    $current_cell = '';
                }
            } else {
                if ( null !== $current_cell && ( 'TD' === $tag_name || 'TH' === $tag_name ) ) {
                    $current_row[] = $current_cell;
                    $current_cell  = null;
                } elseif ( null !== $current_row && 'TR' === $tag_name ) {
                    $rows[]      = $current_row;
                    $current_row = null;
                }
            }

            continue;
        }

        if ( null !== $current_cell && '#text' === $token_type ) {
            $current_cell .= $processor->get_modifiable_text();
        }
    }

    if ( null !== $current_cell && null !== $current_row ) {
        $current_row[] = $current_cell;
    }

    if ( null !== $current_row ) {
        $rows[] = $current_row;
    }

    return $rows;
}
