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

        if ( '#tag' === $token_type && null !== $token_name && preg_match( '/^H([1-6])$/', $token_name, $matches ) ) {
            if ( $processor->is_tag_closer() ) {
                if ( null !== $current_heading && $current_heading['tag'] === $token_name ) {
                    $toc[] = array(
                        'level' => $current_heading['level'],
                        'text'  => $current_heading['text'],
                    );
                    $current_heading = null;
                }
            } else {
                $current_heading = array(
                    'tag'   => $token_name,
                    'level' => (int) $matches[1],
                    'text'  => '',
                );

                $current_heading['text'] .= $processor->get_modifiable_text();
            }

            continue;
        }

        if ( null === $current_heading ) {
            continue;
        }

        if ( '#text' === $token_type ) {
            $current_heading['text'] .= $processor->get_modifiable_text();
            continue;
        }

        if ( '#tag' === $token_type && ! $processor->is_tag_closer() ) {
            $current_heading['text'] .= $processor->get_modifiable_text();
        }
    }

    return $toc;
}
