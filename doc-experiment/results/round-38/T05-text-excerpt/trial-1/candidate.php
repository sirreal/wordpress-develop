<?php
function html_text_excerpt( string $html, int $max_codepoints ): string {
    if ( $max_codepoints <= 0 ) {
        return '';
    }

    $truncate = static function ( string $text ) use ( $max_codepoints ): string {
        if ( function_exists( 'mb_strlen' ) && function_exists( 'mb_substr' ) ) {
            if ( mb_strlen( $text, 'UTF-8' ) <= $max_codepoints ) {
                return $text;
            }

            return mb_substr( $text, 0, $max_codepoints, 'UTF-8' );
        }

        if ( 1 !== preg_match_all( '/./us', $text, $matches ) || count( $matches[0] ) <= $max_codepoints ) {
            return $text;
        }

        return implode( '', array_slice( $matches[0], 0, $max_codepoints ) );
    };

    $text      = '';
    $processor = WP_HTML_Processor::create_fragment( $html );

    if ( null !== $processor ) {
        while ( $processor->next_token() ) {
            if ( '#text' === $processor->get_token_type() ) {
                $text .= $processor->get_modifiable_text();
                continue;
            }

            if ( '#tag' === $processor->get_token_type() && ! $processor->is_tag_closer() ) {
                $tag_name = $processor->get_tag();
                if ( 'TITLE' === $tag_name || 'TEXTAREA' === $tag_name ) {
                    $text .= $processor->get_modifiable_text();
                }
            }
        }

        if ( null === $processor->get_last_error() ) {
            return $truncate( $text );
        }
    }

    $text      = '';
    $processor = new WP_HTML_Tag_Processor( $html );

    while ( $processor->next_token() ) {
        if ( '#text' === $processor->get_token_type() ) {
            $text .= $processor->get_modifiable_text();
            continue;
        }

        if ( '#tag' === $processor->get_token_type() && ! $processor->is_tag_closer() ) {
            $tag_name = $processor->get_tag();
            if ( 'TITLE' === $tag_name || 'TEXTAREA' === $tag_name ) {
                $text .= $processor->get_modifiable_text();
            }
        }
    }

    return $truncate( $text );
}
