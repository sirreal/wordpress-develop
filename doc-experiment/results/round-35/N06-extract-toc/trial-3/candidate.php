<?php
function extract_toc( string $html ): array {
    $processor = WP_HTML_Processor::create_fragment( $html );

    if ( null === $processor ) {
        return array();
    }

    $toc           = array();
    $current_tag   = null;
    $current_level = null;
    $current_text  = '';

    while ( $processor->next_token() ) {
        if ( '#tag' === $processor->get_token_type() ) {
            $tag = $processor->get_tag();

            if ( null === $tag ) {
                continue;
            }

            if ( $processor->is_tag_closer() ) {
                if ( null !== $current_tag && $tag === $current_tag ) {
                    $toc[] = array(
                        'level' => $current_level,
                        'text'  => $current_text,
                    );

                    $current_tag   = null;
                    $current_level = null;
                    $current_text  = '';
                }

                continue;
            }

            if ( null === $current_tag && preg_match( '/^H([1-6])$/', $tag, $matches ) ) {
                $current_tag   = $tag;
                $current_level = (int) $matches[1];
                $current_text  = '';
            }

            continue;
        }

        if ( null !== $current_tag && '#text' === $processor->get_token_type() ) {
            $current_text .= $processor->get_modifiable_text();
        }
    }

    return $toc;
}
