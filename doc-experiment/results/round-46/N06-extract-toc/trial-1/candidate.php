<?php
function extract_toc( string $html ): array {
    $processor = WP_HTML_Processor::create_fragment( $html );

    if ( null === $processor ) {
        return array();
    }

    $toc           = array();
    $current_level = null;
    $current_text  = '';
    $heading_depth = null;

    while ( $processor->next_token() ) {
        if ( null !== $heading_depth && $processor->get_current_depth() < $heading_depth ) {
            $toc[] = array(
                'level' => $current_level,
                'text'  => $current_text,
            );

            $current_level = null;
            $current_text  = '';
            $heading_depth = null;
        }

        if ( '#tag' === $processor->get_token_type() ) {
            $tag = $processor->get_tag();

            if ( ! $processor->is_tag_closer() && in_array( $tag, array( 'H1', 'H2', 'H3', 'H4', 'H5', 'H6' ), true ) ) {
                $current_level = (int) substr( $tag, 1 );
                $current_text  = '';
                $heading_depth = $processor->get_current_depth();
                continue;
            }
        }

        if ( null !== $heading_depth && '#text' === $processor->get_token_type() ) {
            $current_text .= $processor->get_modifiable_text();
        }
    }

    if ( null !== $heading_depth ) {
        $toc[] = array(
            'level' => $current_level,
            'text'  => $current_text,
        );
    }

    return $toc;
}
