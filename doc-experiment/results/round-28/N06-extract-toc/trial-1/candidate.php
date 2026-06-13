<?php

function extract_toc( string $html ): array {
    $processor = WP_HTML_Processor::create_fragment( $html );

    if ( null === $processor ) {
        return array();
    }

    $toc           = array();
    $current_level = null;
    $current_text  = '';

    while ( $processor->next_token() ) {
        $token_name = $processor->get_token_name();

        if ( null !== $current_level && '#text' === $processor->get_token_type() ) {
            $current_text .= $processor->get_modifiable_text();
            continue;
        }

        if ( null === $token_name || 2 !== strlen( $token_name ) || 'H' !== $token_name[0] ) {
            continue;
        }

        $level = (int) $token_name[1];
        if ( $level < 1 || $level > 6 ) {
            continue;
        }

        if ( $processor->is_tag_closer() ) {
            if ( $current_level === $level ) {
                $toc[] = array(
                    'level' => $current_level,
                    'text'  => $current_text,
                );
                $current_level = null;
                $current_text  = '';
            }
        } else {
            $current_level = $level;
            $current_text  = '';
        }
    }

    return $toc;
}
