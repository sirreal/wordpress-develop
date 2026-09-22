<?php
function extract_toc( string $html ): array {
    $processor = WP_HTML_Processor::create_fragment( $html );
    if ( null === $processor ) {
        return array();
    }

    $toc           = array();
    $heading_depth = null;
    $heading_level = null;
    $heading_text  = '';

    while ( $processor->next_token() ) {
        if ( null !== $heading_depth && $processor->get_current_depth() < $heading_depth ) {
            $toc[] = array(
                'level' => $heading_level,
                'text'  => $heading_text,
            );

            $heading_depth = null;
            $heading_level = null;
            $heading_text  = '';
        }

        if ( null !== $heading_depth ) {
            if ( '#text' === $processor->get_token_type() ) {
                $heading_text .= $processor->get_modifiable_text();
            }
            continue;
        }

        if ( '#tag' !== $processor->get_token_type() || $processor->is_tag_closer() ) {
            continue;
        }

        $tag = $processor->get_tag();
        if ( null === $tag || 2 !== strlen( $tag ) || 'H' !== $tag[0] || $tag[1] < '1' || $tag[1] > '6' ) {
            continue;
        }

        $heading_depth = $processor->get_current_depth();
        $heading_level = (int) $tag[1];
        $heading_text  = '';
    }

    if ( null !== $heading_depth ) {
        $toc[] = array(
            'level' => $heading_level,
            'text'  => $heading_text,
        );
    }

    return $toc;
}
