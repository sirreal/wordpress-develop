<?php

function extract_toc( string $html ): array {
    $processor = WP_HTML_Processor::create_fragment( $html );

    if ( null === $processor ) {
        return array();
    }

    $toc             = array();
    $current_heading = null;

    while ( $processor->next_token() ) {
        $tag = $processor->get_tag();

        if ( null !== $tag && 0 === strpos( $tag, 'H' ) && 2 === strlen( $tag ) ) {
            $level = (int) substr( $tag, 1, 1 );

            if ( $level >= 1 && $level <= 6 ) {
                if ( $processor->is_tag_closer() ) {
                    if ( $current_heading === $tag ) {
                        $current_heading = null;
                    }
                } else {
                    $toc[] = array(
                        'level' => $level,
                        'text'  => '',
                    );
                    $current_heading = $tag;
                }

                continue;
            }
        }

        if ( null !== $current_heading && '#text' === $processor->get_token_type() ) {
            $toc[ count( $toc ) - 1 ]['text'] .= $processor->get_modifiable_text();
        }
    }

    return $toc;
}
