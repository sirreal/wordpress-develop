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

        if ( null !== $current_heading ) {
            if ( '#text' === $token_type ) {
                $current_heading['text'] .= $processor->get_modifiable_text();
                continue;
            }

            if (
                '#tag' === $token_type &&
                $processor->is_tag_closer() &&
                $processor->get_tag() === $current_heading['tag']
            ) {
                $toc[] = array(
                    'level' => $current_heading['level'],
                    'text'  => $current_heading['text'],
                );
                $current_heading = null;
            }
        }

        if ( '#tag' !== $token_type || $processor->is_tag_closer() ) {
            continue;
        }

        $tag = $processor->get_tag();
        if (
            'H1' !== $tag &&
            'H2' !== $tag &&
            'H3' !== $tag &&
            'H4' !== $tag &&
            'H5' !== $tag &&
            'H6' !== $tag
        ) {
            continue;
        }

        $current_heading = array(
            'tag'   => $tag,
            'level' => (int) substr( $tag, 1 ),
            'text'  => '',
        );
    }

    if ( null !== $current_heading ) {
        $toc[] = array(
            'level' => $current_heading['level'],
            'text'  => $current_heading['text'],
        );
    }

    return $toc;
}
