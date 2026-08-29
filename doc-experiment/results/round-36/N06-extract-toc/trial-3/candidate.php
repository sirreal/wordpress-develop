<?php
function extract_toc( string $html ): array {
    $processor = WP_HTML_Processor::create_fragment( $html );
    if ( null === $processor ) {
        return array();
    }

    $toc                 = array();
    $current_heading_tag = null;
    $current_heading_key = null;
    $current_depth       = null;

    while ( $processor->next_token() ) {
        $token_type = $processor->get_token_type();
        $token_name = $processor->get_token_name();

        if ( null !== $current_heading_key ) {
            if ( '#tag' === $token_type && $processor->is_tag_closer() && $token_name === $current_heading_tag ) {
                $current_heading_tag = null;
                $current_heading_key = null;
                $current_depth       = null;
                continue;
            }

            if ( '#text' === $token_type ) {
                $toc[ $current_heading_key ]['text'] .= $processor->get_modifiable_text();
                continue;
            }

            if (
                '#tag' === $token_type &&
                ! $processor->is_tag_closer() &&
                $processor->get_current_depth() >= $current_depth &&
                in_array( $token_name, array( 'SCRIPT', 'STYLE', 'TEXTAREA', 'TITLE' ), true )
            ) {
                $toc[ $current_heading_key ]['text'] .= $processor->get_modifiable_text();
                continue;
            }
        }

        if (
            '#tag' === $token_type &&
            ! $processor->is_tag_closer() &&
            null !== $token_name &&
            2 === strlen( $token_name ) &&
            'H' === $token_name[0] &&
            $token_name[1] >= '1' &&
            $token_name[1] <= '6'
        ) {
            $toc[] = array(
                'level' => (int) $token_name[1],
                'text'  => '',
            );

            $current_heading_key = count( $toc ) - 1;
            $current_heading_tag = $token_name;
            $current_depth       = $processor->get_current_depth();
        }
    }

    return $toc;
}
