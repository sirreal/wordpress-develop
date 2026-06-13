<?php
function extract_toc( string $html ): array {
    $processor = WP_HTML_Processor::create_fragment( $html );
    if ( null === $processor ) {
        return array();
    }

    $toc                 = array();
    $current_heading     = null;
    $current_heading_tag = null;

    while ( $processor->next_token() ) {
        $token_type = $processor->get_token_type();
        $token_name = $processor->get_token_name();

        if ( null !== $current_heading ) {
            if ( '#text' === $token_type ) {
                $current_heading['text'] .= $processor->get_modifiable_text();
                continue;
            }

            if ( '#tag' === $token_type ) {
                if ( $processor->is_tag_closer() ) {
                    if ( $token_name === $current_heading_tag ) {
                        $toc[]               = $current_heading;
                        $current_heading     = null;
                        $current_heading_tag = null;
                    }
                    continue;
                }

                $current_heading['text'] .= $processor->get_modifiable_text();
                continue;
            }

            continue;
        }

        if ( '#tag' !== $token_type || $processor->is_tag_closer() ) {
            continue;
        }

        if ( preg_match( '/^H([1-6])$/', $token_name, $matches ) ) {
            $current_heading = array(
                'level' => (int) $matches[1],
                'text'  => $processor->get_modifiable_text(),
            );
            $current_heading_tag = $token_name;
        }
    }

    if ( null !== $current_heading ) {
        $toc[] = $current_heading;
    }

    return $toc;
}
