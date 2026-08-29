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
    $current_cell = null;

    while ( $processor->next_token() ) {
        if ( $processor->get_current_depth() < $table_depth ) {
            break;
        }

        $token_type = $processor->get_token_type();
        $token_name = $processor->get_token_name();

        if ( '#tag' === $token_type ) {
            if ( ! $processor->is_tag_closer() ) {
                if ( 'TR' === $token_name ) {
                    $current_row = array();
                } elseif ( 'TD' === $token_name || 'TH' === $token_name ) {
                    if ( null === $current_row ) {
                        $current_row = array();
                    }
                    $current_cell = '';
                }
            } else {
                if ( 'TD' === $token_name || 'TH' === $token_name ) {
                    if ( null !== $current_row && null !== $current_cell ) {
                        $current_row[] = $current_cell;
                    }
                    $current_cell = null;
                } elseif ( 'TR' === $token_name ) {
                    if ( null !== $current_row ) {
                        $rows[] = $current_row;
                    }
                    $current_row = null;
                }
            }
        } elseif ( null !== $current_cell && '#text' === $token_type ) {
            $current_cell .= $processor->get_modifiable_text();
        }
    }

    if ( null !== $processor->get_last_error() ) {
        return array();
    }

    return $rows;
}
