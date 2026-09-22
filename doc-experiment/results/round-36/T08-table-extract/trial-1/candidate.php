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
    $row         = null;
    $cell        = null;
    $cell_depth  = 0;

    while ( $processor->next_token() ) {
        if ( $processor->get_current_depth() < $table_depth ) {
            break;
        }

        $token_type = $processor->get_token_type();
        $token_name = $processor->get_token_name();

        if ( '#tag' === $token_type ) {
            if ( 'TR' === $token_name ) {
                if ( $processor->is_tag_closer() ) {
                    if ( null !== $row ) {
                        $rows[] = $row;
                        $row    = null;
                    }
                } else {
                    $row = array();
                }
                continue;
            }

            if ( 'TD' === $token_name || 'TH' === $token_name ) {
                if ( $processor->is_tag_closer() ) {
                    if ( null !== $row && null !== $cell ) {
                        $row[] = $cell;
                        $cell  = null;
                    }
                } else {
                    $cell       = '';
                    $cell_depth = $processor->get_current_depth();
                }
                continue;
            }

            if ( null !== $cell && ! $processor->is_tag_closer() ) {
                if ( 'SCRIPT' === $token_name || 'STYLE' === $token_name || 'TEXTAREA' === $token_name || 'TITLE' === $token_name ) {
                    $cell .= $processor->get_modifiable_text();
                }
            }

            continue;
        }

        if ( null !== $cell && '#text' === $token_type && $processor->get_current_depth() >= $cell_depth ) {
            $cell .= $processor->get_modifiable_text();
        }
    }

    if ( null !== $cell && null !== $row ) {
        $row[] = $cell;
    }
    if ( null !== $row ) {
        $rows[] = $row;
    }

    return $rows;
}
