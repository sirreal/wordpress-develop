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
    $cell_text_elements = array(
        'IFRAME'   => true,
        'NOEMBED'  => true,
        'NOFRAMES' => true,
        'NOSCRIPT' => true,
        'SCRIPT'   => true,
        'STYLE'    => true,
        'TEXTAREA' => true,
        'TITLE'    => true,
        'XMP'      => true,
    );

    while ( $processor->next_token() && $processor->get_current_depth() >= $table_depth ) {
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

        if ( $processor->is_tag_closer() ) {
            if ( ( 'TD' === $token_name || 'TH' === $token_name ) && null !== $current_cell && null !== $current_row ) {
                $current_row[] = $current_cell;
                $current_cell  = null;
            } elseif ( 'TR' === $token_name && null !== $current_row ) {
                $rows[]      = $current_row;
                $current_row = null;
            }
            continue;
        }

        if ( 'TR' === $token_name ) {
            $current_row = array();
            continue;
        }

        if ( 'TD' === $token_name || 'TH' === $token_name ) {
            if ( null === $current_row ) {
                $current_row = array();
            }
            $current_cell = '';
            continue;
        }

        if ( null !== $current_cell && isset( $cell_text_elements[ $token_name ] ) ) {
            $current_cell .= $processor->get_modifiable_text();
        }
    }

    if ( null !== $processor->get_last_error() ) {
        return array();
    }

    return $rows;
}
