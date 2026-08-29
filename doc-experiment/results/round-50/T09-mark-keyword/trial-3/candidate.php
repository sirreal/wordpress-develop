<?php
function mark_keyword( string $html, string $keyword ): string {
    $processor = WP_HTML_Processor::create_fragment( $html );
    if ( null === $processor ) {
        return $html;
    }

    $output        = '';
    $wrapped_cache = array();

    while ( $processor->next_token() ) {
        if ( '#text' !== $processor->get_token_type() ) {
            $output .= $processor->serialize_token();
            continue;
        }

        $text = $processor->get_modifiable_text();
        if ( ! str_contains( $text, $keyword ) ) {
            $output .= $processor->serialize_token();
            continue;
        }

        if ( ! array_key_exists( $text, $wrapped_cache ) ) {
            $mark = WP_HTML_Processor::create_fragment( '<mark>.</mark>' );
            if ( null === $mark ) {
                return $html;
            }

            while ( $mark->next_token() ) {
                if ( '#text' === $mark->get_token_type() ) {
                    if ( ! $mark->set_modifiable_text( $text ) ) {
                        return $html;
                    }
                    break;
                }
            }

            $wrapped_cache[ $text ] = $mark->get_updated_html();
        }

        $output .= $wrapped_cache[ $text ];
    }

    if ( null !== $processor->get_last_error() ) {
        return $html;
    }

    return $output;
}
