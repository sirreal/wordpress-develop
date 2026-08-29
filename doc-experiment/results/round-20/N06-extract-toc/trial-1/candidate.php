<?php
function extract_toc( string $html ): array {
    $processor = WP_HTML_Processor::create_fragment( $html );

    if ( null === $processor ) {
        return array();
    }

    $toc             = array();
    $current_index   = null;
    $current_heading = null;

    while ( $processor->next_token() ) {
        $tag_name = $processor->get_tag();

        if ( null !== $current_index ) {
            if ( '#text' === $processor->get_token_type() ) {
                $toc[ $current_index ]['text'] .= $processor->get_modifiable_text();
            } elseif ( '#tag' === $processor->get_token_type() && ! $processor->is_tag_closer() ) {
                $toc[ $current_index ]['text'] .= $processor->get_modifiable_text();
            }

            if ( $processor->is_tag_closer() && $tag_name === $current_heading ) {
                $current_index   = null;
                $current_heading = null;
            }

            continue;
        }

        if ( '#tag' !== $processor->get_token_type() || $processor->is_tag_closer() || null === $tag_name ) {
            continue;
        }

        if ( 1 === preg_match( '/^H([1-6])$/', $tag_name, $matches ) ) {
            $toc[] = array(
                'level' => (int) $matches[1],
                'text'  => '',
            );

            $current_index   = count( $toc ) - 1;
            $current_heading = $tag_name;
            $toc[ $current_index ]['text'] .= $processor->get_modifiable_text();
        }
    }

    return $toc;
}
