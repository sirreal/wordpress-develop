<?php
function mark_keyword( string $html, string $keyword ): string {
    $processor = WP_HTML_Processor::create_fragment( $html );
    if ( null === $processor ) {
        return $html;
    }

    $output = '';

    while ( $processor->next_token() ) {
        if ( '#text' === $processor->get_token_type() ) {
            $text = $processor->get_modifiable_text();

            if ( str_contains( $text, $keyword ) ) {
                $wrapper = new WP_HTML_Tag_Processor( '<mark>.</mark>' );
                while ( $wrapper->next_token() ) {
                    if ( '#text' === $wrapper->get_token_type() ) {
                        $wrapper->set_modifiable_text( $text );
                        break;
                    }
                }

                $output .= $wrapper->get_updated_html();
                continue;
            }
        }

        $output .= $processor->serialize_token();
    }

    if ( null !== $processor->get_last_error() ) {
        return $html;
    }

    return $output;
}
