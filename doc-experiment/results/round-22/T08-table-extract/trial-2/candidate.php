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

    while ( $processor->next_token() && $processor->get_current_depth() >= $table_depth ) {
        if ( '#text' === $processor->get_token_type() ) {
            if ( null !== $current_cell ) {
                $current_cell .= $processor->get_modifiable_text();
            }
            continue;
        }

        if ( '#tag' !== $processor->get_token_type() ) {
            continue;
        }

        $tag       = $processor->get_tag();
        $is_closer = $processor->is_tag_closer();

        if ( ! $is_closer ) {
            if ( 'TR' === $tag ) {
                $current_row = array();
                continue;
            }

            if ( 'TD' === $tag || 'TH' === $tag ) {
                if ( null !== $current_row ) {
                    $current_cell = '';
                }
                continue;
            }

            if (
                null !== $current_cell &&
                ( 'SCRIPT' === $tag || 'STYLE' === $tag || 'TEXTAREA' === $tag || 'TITLE' === $tag )
            ) {
                $current_cell .= $processor->get_modifiable_text();
            }

            continue;
        }

        if ( 'TD' === $tag || 'TH' === $tag ) {
            if ( null !== $current_row && null !== $current_cell ) {
                $current_row[] = $current_cell;
                $current_cell  = null;
            }
            continue;
        }

        if ( 'TR' === $tag ) {
            if ( null !== $current_row ) {
                if ( null !== $current_cell ) {
                    $current_row[] = $current_cell;
                    $current_cell  = null;
                }
                $rows[]      = $current_row;
                $current_row = null;
            }
        }
    }

    return $rows;
}
