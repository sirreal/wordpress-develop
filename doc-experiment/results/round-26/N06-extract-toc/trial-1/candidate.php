<?php

function extract_toc( string $html ): array {
    $processor = WP_HTML_Processor::create_fragment( $html );
    if ( null === $processor ) {
        return array();
    }

    $toc                 = array();
    $current_level       = null;
    $current_text        = '';
    $current_heading_tag = null;
    $heading_depth       = 0;
    $text_only_tags      = array(
        'SCRIPT',
        'STYLE',
        'TITLE',
        'TEXTAREA',
        'IFRAME',
        'NOSCRIPT',
        'NOEMBED',
        'NOFRAME',
        'NOFRAMES',
        'XMP',
    );

    while ( $processor->next_token() ) {
        if ( null !== $current_level && $processor->get_current_depth() < $heading_depth ) {
            $toc[] = array(
                'level' => $current_level,
                'text'  => $current_text,
            );

            $current_level       = null;
            $current_text        = '';
            $current_heading_tag = null;
            $heading_depth       = 0;
        }

        $token_type = $processor->get_token_type();

        if ( null !== $current_level ) {
            if ( '#text' === $token_type ) {
                $current_text .= $processor->get_modifiable_text();
                continue;
            }

            if ( '#tag' === $token_type && ! $processor->is_tag_closer() ) {
                $tag = $processor->get_tag();
                if ( null !== $tag && in_array( $tag, $text_only_tags, true ) ) {
                    $current_text .= $processor->get_modifiable_text();
                }
            }

            continue;
        }

        if ( '#tag' !== $token_type || $processor->is_tag_closer() ) {
            continue;
        }

        $tag = $processor->get_tag();
        if ( null === $tag || ! preg_match( '/^H([1-6])$/', $tag, $matches ) ) {
            continue;
        }

        $current_level       = (int) $matches[1];
        $current_text        = '';
        $current_heading_tag = $tag;
        $heading_depth       = $processor->get_current_depth();
    }

    if ( null !== $current_level ) {
        $toc[] = array(
            'level' => $current_level,
            'text'  => $current_text,
        );
    }

    return $toc;
}
