<?php
function extract_toc( string $html ): array {
    $processor = WP_HTML_Processor::create_fragment( $html );

    if ( null === $processor ) {
        return array();
    }

    $toc = array();

    while ( $processor->next_token() ) {
        if ( '#tag' !== $processor->get_token_type() || $processor->is_tag_closer() ) {
            continue;
        }

        $tag = $processor->get_tag();
        if ( ! in_array( $tag, array( 'H1', 'H2', 'H3', 'H4', 'H5', 'H6' ), true ) ) {
            continue;
        }

        $heading_depth = $processor->get_current_depth();
        $text          = '';

        while ( $processor->next_token() && $processor->get_current_depth() >= $heading_depth ) {
            if ( '#text' === $processor->get_token_type() ) {
                $text .= $processor->get_modifiable_text();
                continue;
            }

            if ( '#tag' === $processor->get_token_type() && ! $processor->is_tag_closer() ) {
                $token_name = $processor->get_token_name();
                if ( in_array( $token_name, array( 'SCRIPT', 'STYLE', 'TITLE', 'TEXTAREA' ), true ) ) {
                    $text .= $processor->get_modifiable_text();
                }
            }
        }

        $toc[] = array(
            'level' => (int) substr( $tag, 1 ),
            'text'  => $text,
        );
    }

    return $toc;
}
