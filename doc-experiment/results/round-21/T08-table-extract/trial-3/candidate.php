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
        $token_name = $processor->get_token_name();

        if ( '#tag' === $token_type ) {
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
                } else {
                    $current_cell = '';
                }

                continue;
            }

            if (
                null !== $current_cell &&
                ! $processor->is_tag_closer() &&
                (
                    'SCRIPT' === $token_name ||
                    'STYLE' === $token_name ||
                    'TEXTAREA' === $token_name ||
                    'TITLE' === $token_name ||
                    'IFRAME' === $token_name ||
                    'NOSCRIPT' === $token_name ||
                    'NOEMBED' === $token_name ||
                    'NOFRAMES' === $token_name ||
                    'XMP' === $token_name
                )
            ) {
                $current_cell .= $processor->get_modifiable_text();
            }

            continue;
        }

        if ( null !== $current_cell && '#text' === $token_type ) {
            $current_cell .= $processor->get_modifiable_text();
        }
    }

    return $rows;
}
