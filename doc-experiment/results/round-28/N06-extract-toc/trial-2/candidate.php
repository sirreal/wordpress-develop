<?php
function extract_toc( string $html ): array {
    $processor = WP_HTML_Processor::create_fragment( $html );

    if ( null === $processor ) {
        return array();
    }

    $toc           = array();
    $current_level = null;
    $current_text  = '';
    $heading_depth = 0;

    while ( $processor->next_token() ) {
        if ( null !== $current_level && $processor->get_current_depth() < $heading_depth ) {
            $toc[] = array(
                'level' => $current_level,
                'text'  => $current_text,
            );

            $current_level = null;
            $current_text  = '';
            $heading_depth = 0;
        }

        $tag_name = $processor->get_tag();
        if ( null !== $tag_name && ! $processor->is_tag_closer() && 2 === strlen( $tag_name ) && 'H' === $tag_name[0] ) {
            $level = (int) $tag_name[1];
            if ( $level >= 1 && $level <= 6 ) {
                $current_level = $level;
                $current_text  = '';
                $heading_depth = $processor->get_current_depth();
                continue;
            }
        }

        if ( null !== $current_level && '#text' === $processor->get_token_type() ) {
            $current_text .= $processor->get_modifiable_text();
        }
    }

    if ( null !== $current_level ) {
        $toc[] = array(
            'level' => $current_level,
            'text'  => $current_text,
        );
    }

    return $toc;
}
