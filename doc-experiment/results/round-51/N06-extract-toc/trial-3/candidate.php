<?php

function extract_toc( string $html ): array {
    $processor = WP_HTML_Processor::create_fragment( $html );
    if ( null === $processor ) {
        return array();
    }

    $toc             = array();
    $current_heading = null;
    $current_text    = '';

    while ( $processor->next_token() ) {
        if ( '#tag' === $processor->get_token_type() ) {
            $tag = $processor->get_tag();

            if ( null !== $tag && preg_match( '/^H([1-6])$/', $tag, $matches ) ) {
                if ( $processor->is_tag_closer() ) {
                    if ( null !== $current_heading && $current_heading === $tag ) {
                        $toc[] = array(
                            'level' => (int) substr( $tag, 1 ),
                            'text'  => $current_text,
                        );
                        $current_heading = null;
                        $current_text    = '';
                    }
                } else {
                    $current_heading = $tag;
                    $current_text    = '';
                }
            }

            continue;
        }

        if ( null !== $current_heading && '#text' === $processor->get_token_type() ) {
            $current_text .= $processor->get_modifiable_text();
        }
    }

    if ( $processor->paused_at_incomplete_token() || null !== $processor->get_last_error() ) {
        return array();
    }

    return $toc;
}
