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

    while ( $processor->next_token() && $processor->get_current_depth() >= $table_depth ) {
        $token_type = $processor->get_token_type();
        $token_name = $processor->get_token_name();

        if ( '#tag' === $token_type ) {
            if ( $processor->is_tag_closer() ) {
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

                continue;
            }

            if ( 'TR' === $token_name ) {
                $current_row = array();
                $current_cell = null;
                continue;
            }

            if ( 'TD' === $token_name || 'TH' === $token_name ) {
                if ( null === $current_row ) {
                    $current_row = array();
                }
                $current_cell = '';
                continue;
            }

            if ( null !== $current_cell ) {
                $text = $processor->get_modifiable_text();
                if ( '' !== $text ) {
                    $current_cell .= $text;
                }
            }

            continue;
        }

        if ( null !== $current_cell && '#text' === $token_type ) {
            $current_cell .= $processor->get_modifiable_text();
        }
    }

    return $rows;
}
