<?php

function extract_toc( string $html ): array {
    $processor = WP_HTML_Processor::create_fragment( $html );

    if ( null === $processor ) {
        return array();
    }

    $toc             = array();
    $current_level   = null;
    $current_tag     = null;
    $current_text    = '';
    $special_textual = array( 'SCRIPT', 'STYLE', 'TEXTAREA', 'TITLE' );

    while ( $processor->next_token() ) {
        $token_type = $processor->get_token_type();
        $tag_name   = $processor->get_tag();

        if ( '#tag' === $token_type && null !== $tag_name ) {
            if ( ! $processor->is_tag_closer() && preg_match( '/^H([1-6])$/', $tag_name, $matches ) ) {
                $current_level = (int) $matches[1];
                $current_tag   = $tag_name;
                $current_text  = '';
                continue;
            }

            if ( null !== $current_level && $processor->is_tag_closer() && $tag_name === $current_tag ) {
                $toc[] = array(
                    'level' => $current_level,
                    'text'  => $current_text,
                );

                $current_level = null;
                $current_tag   = null;
                $current_text  = '';
                continue;
            }

            if ( null !== $current_level && ! $processor->is_tag_closer() && in_array( $tag_name, $special_textual, true ) ) {
                $current_text .= $processor->get_modifiable_text();
                continue;
            }
        }

        if ( null !== $current_level && '#text' === $token_type ) {
            $current_text .= $processor->get_modifiable_text();
        }
    }

    if ( $processor->paused_at_incomplete_token() || null !== $processor->get_last_error() ) {
        return array();
    }

    return $toc;
}
