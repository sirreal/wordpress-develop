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
    $text_only_tags  = array( 'SCRIPT', 'STYLE', 'TEXTAREA', 'TITLE' );

    while ( $processor->next_token() ) {
        if ( '#text' === $processor->get_token_type() ) {
            if ( null !== $current_level ) {
                $current_text .= $processor->get_modifiable_text();
            }
            continue;
        }

        $tag = $processor->get_tag();
        if ( null === $tag ) {
            continue;
        }

        if ( null !== $current_level ) {
            if ( $processor->is_tag_closer() && $tag === $current_tag ) {
                $toc[] = array(
                    'level' => $current_level,
                    'text'  => $current_text,
                );
                $current_level = null;
                $current_tag   = null;
                $current_text  = '';
                continue;
            }

            if ( ! $processor->is_tag_closer() && in_array( $tag, $text_only_tags, true ) ) {
                $current_text .= $processor->get_modifiable_text();
            }
        }

        if (
            ! $processor->is_tag_closer() &&
            2 === strlen( $tag ) &&
            'H' === $tag[0] &&
            $tag[1] >= '1' &&
            $tag[1] <= '6'
        ) {
            $current_level = (int) $tag[1];
            $current_tag   = $tag;
            $current_text  = '';
        }
    }

    if ( null !== $current_level ) {
        $toc[] = array(
            'level' => $current_level,
            'text'  => $current_text,
        );
    }

    return $toc;
}
