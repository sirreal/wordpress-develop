<?php
function extract_toc( string $html ): array {
    $processor = WP_HTML_Processor::create_fragment( $html );

    if ( null === $processor ) {
        return array();
    }

    $toc = array();

    while ( $processor->next_tag() ) {
        $tag = $processor->get_tag();

        if ( null === $tag || ! in_array( $tag, array( 'H1', 'H2', 'H3', 'H4', 'H5', 'H6' ), true ) ) {
            continue;
        }

        $level         = (int) substr( $tag, 1 );
        $heading_depth = $processor->get_current_depth();
        $text          = '';

        while ( $processor->next_token() && $processor->get_current_depth() >= $heading_depth ) {
            if ( '#text' === $processor->get_token_type() ) {
                $text .= $processor->get_modifiable_text();
                continue;
            }

            if ( '#tag' === $processor->get_token_type() && ! $processor->is_tag_closer() ) {
                $child_tag = $processor->get_tag();

                if ( in_array( $child_tag, array( 'SCRIPT', 'STYLE', 'TEXTAREA', 'TITLE' ), true ) ) {
                    $text .= $processor->get_modifiable_text();
                }
            }
        }

        $toc[] = array(
            'level' => $level,
            'text'  => $text,
        );
    }

    return $toc;
}
