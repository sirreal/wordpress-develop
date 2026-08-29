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
    $special_text_tags = array( 'SCRIPT' => true, 'STYLE' => true, 'TEXTAREA' => true, 'TITLE' => true );

    while ( $processor->next_token() && $processor->get_current_depth() >= $table_depth ) {
        $token_type = $processor->get_token_type();
        $tag_name   = $processor->get_tag();

        if ( '#tag' === $token_type ) {
            if ( $processor->is_tag_closer() ) {
                if ( ( 'TD' === $tag_name || 'TH' === $tag_name ) && null !== $current_cell && is_array( $current_row ) ) {
                    $current_row[] = $current_cell;
                    $current_cell  = null;
                } elseif ( 'TR' === $tag_name && is_array( $current_row ) ) {
                    $rows[]      = $current_row;
                    $current_row = null;
                }
            } else {
                if ( 'TR' === $tag_name ) {
                    $current_row = array();
                } elseif ( 'TD' === $tag_name || 'TH' === $tag_name ) {
                    if ( ! is_array( $current_row ) ) {
                        $current_row = array();
                    }
                    $current_cell = '';
                } elseif ( null !== $current_cell && isset( $special_text_tags[ $tag_name ] ) ) {
                    $current_cell .= $processor->get_modifiable_text();
                }
            }

            continue;
        }

        if ( '#text' === $token_type && null !== $current_cell ) {
            $current_cell .= $processor->get_modifiable_text();
        }
    }

    if ( null !== $processor->get_last_error() ) {
        return array();
    }

    return $rows;
}
