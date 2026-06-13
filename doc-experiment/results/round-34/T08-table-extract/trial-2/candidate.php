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
    $text_on_opener_tags = array(
        'SCRIPT'   => true,
        'STYLE'    => true,
        'TEXTAREA' => true,
        'TITLE'    => true,
        'IFRAME'   => true,
        'NOEMBED'  => true,
        'NOFRAME'  => true,
        'NOFRAMES' => true,
        'NOSCRIPT' => true,
        'XMP'      => true,
    );

    while ( $processor->next_token() ) {
        if ( $processor->get_current_depth() < $table_depth ) {
            break;
        }

        if ( '#tag' === $processor->get_token_type() ) {
            $tag_name  = $processor->get_tag();
            $is_closer = $processor->is_tag_closer();

            if ( 'TR' === $tag_name ) {
                if ( $is_closer ) {
                    if ( null !== $current_cell && null !== $current_row ) {
                        $current_row[] = $current_cell;
                        $current_cell  = null;
                    }

                    if ( null !== $current_row ) {
                        $rows[]     = $current_row;
                        $current_row = null;
                    }
                } else {
                    $current_row = array();
                }

                continue;
            }

            if ( 'TD' === $tag_name || 'TH' === $tag_name ) {
                if ( $is_closer ) {
                    if ( null !== $current_cell && null !== $current_row ) {
                        $current_row[] = $current_cell;
                        $current_cell  = null;
                    }
                } else {
                    if ( null !== $current_cell && null !== $current_row ) {
                        $current_row[] = $current_cell;
                    }

                    $current_cell = '';
                }

                continue;
            }

            if (
                null !== $current_cell &&
                ! $is_closer &&
                isset( $text_on_opener_tags[ $tag_name ] )
            ) {
                $current_cell .= $processor->get_modifiable_text();
            }

            continue;
        }

        if ( null !== $current_cell && '#text' === $processor->get_token_type() ) {
            $current_cell .= $processor->get_modifiable_text();
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
