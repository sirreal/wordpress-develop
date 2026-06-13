<?php
function extract_toc( string $html ): array {
    $processor = WP_HTML_Processor::create_fragment( $html );
    if ( null === $processor ) {
        return array();
    }

    $toc                   = array();
    $current_heading_index = null;
    $current_heading_depth = null;

    while ( $processor->next_token() ) {
        if ( null !== $current_heading_index && $processor->get_current_depth() < $current_heading_depth ) {
            $current_heading_index = null;
            $current_heading_depth = null;
        }

        $token_type = $processor->get_token_type();

        if ( '#tag' === $token_type ) {
            $tag = $processor->get_tag();

            if ( ! $processor->is_tag_closer() && null !== $tag && 2 === strlen( $tag ) && 'H' === $tag[0] && $tag[1] >= '1' && $tag[1] <= '6' ) {
                $toc[] = array(
                    'level' => (int) $tag[1],
                    'text'  => '',
                );

                $current_heading_index = count( $toc ) - 1;
                $current_heading_depth = $processor->get_current_depth();
                continue;
            }

            if (
                null !== $current_heading_index &&
                ! $processor->is_tag_closer() &&
                in_array( $tag, array( 'SCRIPT', 'STYLE', 'TEXTAREA', 'TITLE' ), true )
            ) {
                $toc[ $current_heading_index ]['text'] .= $processor->get_modifiable_text();
            }

            continue;
        }

        if ( null !== $current_heading_index && '#text' === $token_type ) {
            $toc[ $current_heading_index ]['text'] .= $processor->get_modifiable_text();
        }
    }

    return $toc;
}
