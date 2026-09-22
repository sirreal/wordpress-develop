<?php

function extract_toc( string $html ): array {
    $processor = WP_HTML_Processor::create_fragment( $html );

    if ( null === $processor ) {
        return array();
    }

    $toc           = array();
    $current_level = null;
    $current_text  = '';

    while ( $processor->next_token() ) {
        if ( '#tag' === $processor->get_token_type() ) {
            $tag = $processor->get_tag();

            if ( null !== $tag && 2 === strlen( $tag ) && 'H' === $tag[0] && $tag[1] >= '1' && $tag[1] <= '6' ) {
                if ( $processor->is_tag_closer() ) {
                    if ( null !== $current_level ) {
                        $toc[] = array(
                            'level' => $current_level,
                            'text'  => $current_text,
                        );
                        $current_level = null;
                        $current_text  = '';
                    }
                } else {
                    $current_level = (int) $tag[1];
                    $current_text  = '';
                }
            }

            continue;
        }

        if ( null !== $current_level && '#text' === $processor->get_token_type() ) {
            $current_text .= $processor->get_modifiable_text();
        }
    }

    if ( $processor->paused_at_incomplete_token() || null !== $processor->get_last_error() ) {
        return array();
    }

    return $toc;
}
