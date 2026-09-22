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

        if ( '#text' === $token_type ) {
            if ( null !== $current_heading ) {
                $current_heading['text'] .= $processor->get_modifiable_text();
            }

            continue;
        }

        if ( '#tag' !== $token_type ) {
            continue;
        }

        $tag = $processor->get_tag();

        if ( null === $tag ) {
            continue;
        }

        if ( null !== $current_heading && ! $processor->is_tag_closer() && in_array( $tag, array( 'SCRIPT', 'STYLE', 'TEXTAREA', 'TITLE' ), true ) ) {
            $current_heading['text'] .= $processor->get_modifiable_text();
            continue;
        }

        if ( 2 !== strlen( $tag ) || 'H' !== $tag[0] || $tag[1] < '1' || $tag[1] > '6' ) {
            continue;
        }

        if ( $processor->is_tag_closer() ) {
            if ( null !== $current_heading && $current_heading['tag'] === $tag ) {
                $toc[] = array(
                    'level' => $current_heading['level'],
                    'text'  => $current_heading['text'],
                );
                $current_heading = null;
            }

            continue;
        }

        if ( null !== $current_heading ) {
            $toc[] = array(
                'level' => $current_heading['level'],
                'text'  => $current_heading['text'],
            );
        }

        $current_heading = array(
            'tag'   => $tag,
            'level' => (int) $tag[1],
            'text'  => '',
        );
    }

    if ( null !== $current_heading && null === $processor->get_last_error() ) {
        $toc[] = array(
            'level' => $current_heading['level'],
            'text'  => $current_heading['text'],
        );
    }

    return $toc;
}
