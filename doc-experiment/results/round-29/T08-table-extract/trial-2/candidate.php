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
        $depth = $processor->get_current_depth();
        if ( $depth < $table_depth ) {
            break;
        }

        $token_type = $processor->get_token_type();
        $token_name = $processor->get_token_name();

        if ( '#text' === $token_type ) {
            if ( null !== $current_cell ) {
                $current_cell .= $processor->get_modifiable_text();
            }
            continue;
        }

        if ( '#tag' !== $token_type || null === $token_name ) {
            continue;
        }

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
                if ( null !== $current_row && null !== $current_cell ) {
                    $current_row[] = $current_cell;
                }
                $current_cell = null;
                $cell_depth   = null;
            } else {
                if ( null === $current_row ) {
                    $current_row = array();
                }
                $current_cell = '';
                $cell_depth   = $depth;
            }
            continue;
        }

        if ( null !== $current_cell && $depth < $cell_depth ) {
            if ( null !== $current_row ) {
                $current_row[] = $current_cell;
            }
            $current_cell = null;
            $cell_depth   = null;
        }
    }

    if ( null !== $current_cell && null !== $current_row ) {
        $current_row[] = $current_cell;
    }

    if ( null !== $current_row ) {
        $rows[] = $current_row;
    }

    if ( null !== $processor->get_last_error() ) {
        return array();
    }

    return $rows;
}
