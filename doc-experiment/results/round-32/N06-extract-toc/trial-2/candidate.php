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
        $token_name = $processor->get_token_name();

        if ( '#tag' === $token_type ) {
            if ( null === $token_name ) {
                continue;
            }

            if ( preg_match( '/^H([1-6])$/', $token_name, $matches ) ) {
                if ( $processor->is_tag_closer() ) {
                    if ( null !== $current_heading ) {
                        $toc[]           = $current_heading;
                        $current_heading = null;
                    }
                } else {
                    $current_heading = array(
                        'level' => (int) $matches[1],
                        'text'  => '',
                    );
                }
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
