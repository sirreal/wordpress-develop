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

    $text_only_elements = array(
        'IFRAME',
        'NOEMBED',
        'NOFRAME',
        'NOFRAMES',
        'NOSCRIPT',
        'SCRIPT',
        'STYLE',
        'TEXTAREA',
        'TITLE',
        'XMP',
    );

    while ( $processor->next_token() ) {
        if ( $processor->get_current_depth() < $table_depth ) {
            break;
        }

        if ( '#text' === $processor->get_token_type() ) {
            if ( null !== $current_cell ) {
                $current_cell .= $processor->get_modifiable_text();
            }
            continue;
        }

        if ( '#tag' !== $processor->get_token_type() ) {
            continue;
        }

        $tag = $processor->get_tag();

        if ( $processor->is_tag_closer() ) {
            if ( ( 'TD' === $tag || 'TH' === $tag ) && null !== $current_cell && null !== $current_row ) {
                $current_row[] = $current_cell;
                $current_cell  = null;
            } elseif ( 'TR' === $tag && null !== $current_row ) {
                $rows[]      = $current_row;
                $current_row = null;
            }

            continue;
        }

        if ( 'TR' === $tag ) {
            $current_row = array();
            continue;
        }

        if ( ( 'TD' === $tag || 'TH' === $tag ) && null !== $current_row ) {
            $current_cell = '';
            continue;
        }

        if ( null !== $current_cell && in_array( $tag, $text_only_elements, true ) ) {
            $current_cell .= $processor->get_modifiable_text();
        }
    }

    if ( null !== $processor->get_last_error() || $processor->paused_at_incomplete_token() ) {
        return array();
    }

    return $rows;
}
