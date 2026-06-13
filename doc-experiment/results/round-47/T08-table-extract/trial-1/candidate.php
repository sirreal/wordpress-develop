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
    $in_first_table = true;

    while ( $in_first_table && $processor->next_token() ) {
        if ( $processor->get_current_depth() < $table_depth ) {
            break;
        }

        $token_type = $processor->get_token_type();
        $tag_name   = $processor->get_tag();

        if ( '#text' === $token_type ) {
            if ( null !== $current_cell ) {
                $current_cell .= $processor->get_modifiable_text();
            }
            continue;
        }

        if ( '#tag' !== $token_type || null === $tag_name ) {
            continue;
        }

        if ( 'TR' === $tag_name ) {
            if ( $processor->is_tag_closer() ) {
                if ( null !== $current_cell ) {
                    $current_row[] = $current_cell;
                    $current_cell  = null;
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

        if ( 'TD' === $tag_name || 'TH' === $tag_name ) {
            if ( $processor->is_tag_closer() ) {
                if ( null !== $current_cell && null !== $current_row ) {
                    $current_row[] = $current_cell;
                    $current_cell  = null;
                }
            } elseif ( null !== $current_row ) {
                $current_cell = '';
            }
            continue;
        }

        if ( 'TABLE' === $tag_name && $processor->is_tag_closer() ) {
            $in_first_table = false;
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
