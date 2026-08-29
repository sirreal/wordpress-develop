<?php

function extract_toc( string $html ): array {
    $processor = WP_HTML_Processor::create_fragment( $html );

    if ( null === $processor ) {
        return array();
    }

    $toc             = array();
    $current_heading = null;
    $special_text_elements = array(
        'SCRIPT'   => true,
        'STYLE'    => true,
        'TEXTAREA' => true,
        'TITLE'    => true,
    );

    while ( $processor->next_token() ) {
        $token_type = $processor->get_token_type();
        $token_name = $processor->get_token_name();

        if ( '#tag' === $token_type && null !== $token_name ) {
            if ( ! $processor->is_tag_closer() && preg_match( '/^H([1-6])$/', $token_name, $matches ) ) {
                $toc[] = array(
                    'level' => (int) $matches[1],
                    'text'  => '',
                );
                $current_heading = count( $toc ) - 1;
                continue;
            }

            if ( null !== $current_heading ) {
                if ( $processor->is_tag_closer() && $token_name === 'H' . $toc[ $current_heading ]['level'] ) {
                    $current_heading = null;
                    continue;
                }

                if ( ! $processor->is_tag_closer() && isset( $special_text_elements[ $token_name ] ) ) {
                    $toc[ $current_heading ]['text'] .= $processor->get_modifiable_text();
                }
            }

            continue;
        }

        if ( null !== $current_heading && '#text' === $token_type ) {
            $toc[ $current_heading ]['text'] .= $processor->get_modifiable_text();
        }
    }

    return $toc;
}
