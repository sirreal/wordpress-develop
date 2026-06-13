<?php

function extract_toc( string $html ): array {
    $processor = WP_HTML_Processor::create_fragment( $html );

    if ( null === $processor ) {
        return array();
    }

    $toc             = array();
    $current_heading = null;

    while ( $processor->next_token() ) {
        if ( '#tag' === $processor->get_token_type() ) {
            $tag = $processor->get_tag();

            if ( null !== $tag && preg_match( '/^H[1-6]$/', $tag ) ) {
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
                }

                continue;
            }

            if ( null !== $current_heading && ! $processor->is_tag_closer() ) {
                $current_heading['text'] .= $processor->get_modifiable_text();
            }

            continue;
        }

        if ( null !== $current_heading && '#text' === $processor->get_token_type() ) {
            $current_heading['text'] .= $processor->get_modifiable_text();
        }
    }

    if ( null !== $current_heading ) {
        $toc[] = $current_heading;
    }

    return $toc;
}
