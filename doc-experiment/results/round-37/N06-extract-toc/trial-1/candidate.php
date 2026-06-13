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

            if ( null !== $current_heading ) {
                if ( $processor->is_tag_closer() && $tag === $current_heading['tag'] ) {
                    $toc[]           = array(
                        'level' => $current_heading['level'],
                        'text'  => $current_heading['text'],
                    );
                    $current_heading = null;
                    continue;
                }

                if ( ! $processor->is_tag_closer() && in_array( $tag, array( 'SCRIPT', 'STYLE', 'TEXTAREA', 'TITLE' ), true ) ) {
                    $current_heading['text'] .= $processor->get_modifiable_text();
                    continue;
                }
            }

            if ( null === $current_heading && ! $processor->is_tag_closer() && preg_match( '/^H([1-6])$/', $tag, $matches ) ) {
                $current_heading = array(
                    'tag'   => $tag,
                    'level' => (int) $matches[1],
                    'text'  => '',
                );
            }

            continue;
        }

        if ( null !== $current_heading && '#text' === $processor->get_token_type() ) {
            $current_heading['text'] .= $processor->get_modifiable_text();
        }
    }

    if ( null !== $current_heading ) {
        $toc[] = array(
            'level' => $current_heading['level'],
            'text'  => $current_heading['text'],
        );
    }

    return $toc;
}
