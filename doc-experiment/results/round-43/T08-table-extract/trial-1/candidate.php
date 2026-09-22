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
    $special_text_tags = array( 'SCRIPT', 'STYLE', 'TEXTAREA', 'TITLE', 'IFRAME', 'NOSCRIPT', 'NOEMBED', 'NOFRAMES', 'XMP' );

    while ( $processor->next_token() && $processor->get_current_depth() >= $table_depth ) {
        $token_type = $processor->get_token_type();
        $tag_name   = $processor->get_tag();

        if ( '#tag' === $token_type ) {
            if ( 'TR' === $tag_name ) {
                if ( $processor->is_tag_closer() ) {
                    if ( null !== $current_row ) {
                        $rows[] = $current_row;
                        $current_row = null;
                    }
                } else {
                    $current_row = array();
                }

                continue;
            }

            if ( 'TD' === $tag_name || 'TH' === $tag_name ) {
                if ( $processor->is_tag_closer() ) {
                    if ( null !== $current_row && null !== $current_cell ) {
                        $current_row[] = $current_cell;
                        $current_cell = null;
                    }
                } else {
                    $current_cell = '';
                }

                continue;
            }

            if (
                null !== $current_cell &&
                ! $processor->is_tag_closer() &&
                in_array( $tag_name, $special_text_tags, true )
            ) {
                $current_cell .= $processor->get_modifiable_text();
            }

            continue;
        }

        if ( null !== $current_cell && '#text' === $token_type ) {
            $current_cell .= $processor->get_modifiable_text();
        }
    }

    if ( null !== $processor->get_last_error() ) {
        return array();
    }

    return $rows;
}
