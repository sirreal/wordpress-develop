<?php

function extract_toc( string $html ): array {
    $processor = WP_HTML_Processor::create_fragment( $html );

    if ( null === $processor ) {
        return array();
    }

    $toc             = array();
    $current_heading = null;

    while ( $processor->next_token() ) {
        if ( null !== $current_heading && $processor->get_current_depth() < $current_heading['depth'] ) {
            $toc[] = array(
                'level' => $current_heading['level'],
                'text'  => $current_heading['text'],
            );
            $current_heading = null;
        }

        if ( null !== $current_heading && '#text' === $processor->get_token_type() ) {
            $current_heading['text'] .= $processor->get_modifiable_text();
        }

        if ( '#tag' !== $processor->get_token_type() || $processor->is_tag_closer() ) {
            continue;
        }

        $tag = $processor->get_tag();
        if ( null === $tag || 2 !== strlen( $tag ) || 'H' !== $tag[0] || $tag[1] < '1' || $tag[1] > '6' ) {
            continue;
        }

        $current_heading = array(
            'level' => (int) $tag[1],
            'text'  => '',
            'depth' => $processor->get_current_depth(),
        );
    }

    if ( null !== $current_heading ) {
        $toc[] = array(
            'level' => $current_heading['level'],
            'text'  => $current_heading['text'],
        );
    }

    if ( $processor->paused_at_incomplete_token() || null !== $processor->get_last_error() ) {
        return array();
    }

    return $toc;
}
