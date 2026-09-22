<?php

function extract_toc( string $html ): array {
    $processor = WP_HTML_Processor::create_fragment( $html );

    if ( null === $processor ) {
        return array();
    }

    $toc             = array();
    $current_heading = null;

    while ( $processor->next_token() ) {
        $token_type = $processor->get_token_type();
        $tag        = $processor->get_tag();

        if ( '#tag' === $token_type && null !== $tag && in_array( $tag, array( 'H1', 'H2', 'H3', 'H4', 'H5', 'H6' ), true ) ) {
            if ( $processor->is_tag_closer() ) {
                if ( null !== $current_heading ) {
                    $toc[]           = $current_heading;
                    $current_heading = null;
                }
            } else {
                $current_heading = array(
                    'level' => (int) substr( $tag, 1 ),
                    'text'  => '',
                );

                $text = $processor->get_modifiable_text();
                if ( '' !== $text ) {
                    $current_heading['text'] .= $text;
                }
            }

            continue;
        }

        if ( null === $current_heading ) {
            continue;
        }

        if ( '#text' === $token_type ) {
            $current_heading['text'] .= $processor->get_modifiable_text();
            continue;
        }

        if ( '#tag' === $token_type && ! $processor->is_tag_closer() ) {
            $current_heading['text'] .= $processor->get_modifiable_text();
        }
    }

    if ( null !== $current_heading ) {
        $toc[] = $current_heading;
    }

    return $toc;
}
