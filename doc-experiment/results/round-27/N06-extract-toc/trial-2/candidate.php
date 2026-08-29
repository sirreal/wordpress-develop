<?php

function extract_toc( string $html ): array {
    $processor = WP_HTML_Processor::create_fragment( $html );

    if ( null === $processor ) {
        return array();
    }

    $toc             = array();
    $current_heading = null;
    $heading_tags    = array(
        'H1' => 1,
        'H2' => 2,
        'H3' => 3,
        'H4' => 4,
        'H5' => 5,
        'H6' => 6,
    );
    $text_on_element_tags = array(
        'SCRIPT'   => true,
        'STYLE'    => true,
        'TEXTAREA' => true,
        'TITLE'    => true,
    );

    while ( $processor->next_token() ) {
        $token_name = $processor->get_token_name();

        if ( null === $token_name ) {
            continue;
        }

        if ( null !== $current_heading ) {
            if ( '#text' === $processor->get_token_type() ) {
                $current_heading['text'] .= $processor->get_modifiable_text();
            } elseif ( isset( $text_on_element_tags[ $token_name ] ) && ! $processor->is_tag_closer() ) {
                $current_heading['text'] .= $processor->get_modifiable_text();
            }

            if ( $token_name === $current_heading['tag'] && $processor->is_tag_closer() ) {
                $toc[]           = array(
                    'level' => $current_heading['level'],
                    'text'  => $current_heading['text'],
                );
                $current_heading = null;
                continue;
            }
        }

        if ( isset( $heading_tags[ $token_name ] ) && ! $processor->is_tag_closer() ) {
            $current_heading = array(
                'tag'   => $token_name,
                'level' => $heading_tags[ $token_name ],
                'text'  => '',
            );
        }
    }

    return $toc;
}
