<?php
function extract_toc( string $html ): array {
    $processor = WP_HTML_Processor::create_fragment( $html );
    if ( null === $processor ) {
        return array();
    }

    $toc                  = array();
    $current_heading_tag  = null;
    $current_heading_index = null;

    while ( $processor->next_token() ) {
        if ( '#tag' === $processor->get_token_type() ) {
            $tag = $processor->get_tag();

            if ( null !== $tag && preg_match( '/^H([1-6])$/', $tag, $matches ) ) {
                if ( $processor->is_tag_closer() ) {
                    if ( $current_heading_tag === $tag ) {
                        $current_heading_tag   = null;
                        $current_heading_index = null;
                    }
                } else {
                    $toc[] = array(
                        'level' => (int) $matches[1],
                        'text'  => '',
                    );

                    $current_heading_tag   = $tag;
                    $current_heading_index = count( $toc ) - 1;
                }
            }

            continue;
        }

        if ( null !== $current_heading_index && '#text' === $processor->get_token_type() ) {
            $toc[ $current_heading_index ]['text'] .= $processor->get_modifiable_text();
        }
    }

    return $toc;
}
