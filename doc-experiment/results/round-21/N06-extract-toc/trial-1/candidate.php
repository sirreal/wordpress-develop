<?php

function extract_toc( string $html ): array {
    $processor = WP_HTML_Processor::create_fragment( $html );

    if ( null === $processor ) {
        return array();
    }

    $toc             = array();
    $current_heading = null;

    while ( $processor->next_token() ) {
        $token_name = $processor->get_token_name();

        if ( null === $token_name ) {
            continue;
        }

        if ( in_array( $token_name, array( 'H1', 'H2', 'H3', 'H4', 'H5', 'H6' ), true ) ) {
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
                    'level' => (int) substr( $token_name, 1 ),
                    'text'  => '',
                );
            }

            continue;
        }

        if ( null !== $current_heading && '#text' === $processor->get_token_type() ) {
            $current_heading['text'] .= $processor->get_modifiable_text();
        }
    }

    return $toc;
}
