<?php
function collect_links( string $html ): array {
    $processor = WP_HTML_Processor::create_fragment( $html );
    if ( null === $processor ) {
        return array();
    }

    $links        = array();
    $current_link = null;
    $text_on_tag  = array(
        'SCRIPT'   => true,
        'STYLE'    => true,
        'TITLE'    => true,
        'TEXTAREA' => true,
        'IFRAME'   => true,
        'NOSCRIPT' => true,
        'NOEMBED'  => true,
        'NOFRAME'  => true,
        'NOFRAMES' => true,
        'XMP'      => true,
    );

    while ( $processor->next_token() ) {
        $token_type = $processor->get_token_type();

        if ( '#tag' === $token_type ) {
            $tag = $processor->get_tag();

            if ( 'A' === $tag ) {
                if ( $processor->is_tag_closer() ) {
                    if ( null !== $current_link ) {
                        $links[]      = $current_link;
                        $current_link = null;
                    }
                } else {
                    $href = $processor->get_attribute( 'href' );
                    if ( is_string( $href ) ) {
                        $current_link = array(
                            'href' => $href,
                            'text' => '',
                        );
                    }
                }

                continue;
            }

            if (
                null !== $current_link &&
                ! $processor->is_tag_closer() &&
                isset( $text_on_tag[ $tag ] )
            ) {
                $current_link['text'] .= $processor->get_modifiable_text();
            }

            continue;
        }

        if ( null !== $current_link && '#text' === $token_type ) {
            $current_link['text'] .= $processor->get_modifiable_text();
        }
    }

    return $links;
}
