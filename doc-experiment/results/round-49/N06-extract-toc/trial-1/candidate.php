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
        $tag_name   = $processor->get_tag();

        if ( '#tag' === $token_type && null !== $tag_name ) {
            if ( null === $current_heading ) {
                if ( ! $processor->is_tag_closer() && preg_match( '/^H([1-6])$/', $tag_name, $matches ) ) {
                    $current_heading = array(
                        'level' => (int) $matches[1],
                        'text'  => '',
                    );
                }
            } elseif ( $processor->is_tag_closer() && $tag_name === 'H' . $current_heading['level'] ) {
                $toc[]           = $current_heading;
                $current_heading = null;
            }

            continue;
        }

        if ( null !== $current_heading && '#text' === $token_type ) {
            $current_heading['text'] .= $processor->get_modifiable_text();
        }
    }

    if ( null !== $current_heading ) {
        $toc[] = $current_heading;
    }

    return $toc;
}
