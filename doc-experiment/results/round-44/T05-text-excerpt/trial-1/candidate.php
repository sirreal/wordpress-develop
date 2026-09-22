<?php

function html_text_excerpt( string $html, int $max_codepoints ): string {
    if ( $max_codepoints <= 0 ) {
        return '';
    }

    $codepoint_length = static function ( string $text ): int {
        if ( '' === $text ) {
            return 0;
        }

        if ( function_exists( 'mb_strlen' ) ) {
            return mb_strlen( $text, 'UTF-8' );
        }

        preg_match_all( '/./us', $text, $matches );
        return count( $matches[0] );
    };

    $codepoint_substr = static function ( string $text, int $start, int $length ): string {
        if ( $length <= 0 || '' === $text ) {
            return '';
        }

        if ( function_exists( 'mb_substr' ) ) {
            return mb_substr( $text, $start, $length, 'UTF-8' );
        }

        preg_match_all( '/./us', $text, $matches );
        return implode( '', array_slice( $matches[0], $start, $length ) );
    };

    $excerpt = '';
    $used    = 0;

    $append_text = static function ( string $text ) use ( $max_codepoints, $codepoint_length, $codepoint_substr, &$excerpt, &$used ): bool {
        if ( '' === $text || $used >= $max_codepoints ) {
            return $used >= $max_codepoints;
        }

        $remaining = $max_codepoints - $used;
        $length    = $codepoint_length( $text );

        if ( $length <= $remaining ) {
            $excerpt .= $text;
            $used    += $length;
            return false;
        }

        $excerpt .= $codepoint_substr( $text, 0, $remaining );
        $used     = $max_codepoints;
        return true;
    };

    $processor = WP_HTML_Processor::create_fragment( $html );

    if ( null !== $processor ) {
        while ( $processor->next_token() ) {
            if ( '#text' === $processor->get_token_type() ) {
                if ( $append_text( $processor->get_modifiable_text() ) ) {
                    return $excerpt;
                }

                continue;
            }

            if ( '#tag' === $processor->get_token_type() && ! $processor->is_tag_closer() ) {
                $name = $processor->get_token_name();
                if ( 'TITLE' === $name || 'TEXTAREA' === $name ) {
                    if ( $append_text( $processor->get_modifiable_text() ) ) {
                        return $excerpt;
                    }
                }
            }
        }

        if ( null === $processor->get_last_error() ) {
            return $excerpt;
        }
    }

    $excerpt = '';
    $used    = 0;
    $tags    = new WP_HTML_Tag_Processor( $html );

    while ( $tags->next_token() ) {
        if ( '#text' === $tags->get_token_type() ) {
            if ( $append_text( $tags->get_modifiable_text() ) ) {
                return $excerpt;
            }

            continue;
        }

        if ( '#tag' === $tags->get_token_type() && ! $tags->is_tag_closer() ) {
            $name = $tags->get_token_name();
            if ( 'TITLE' === $name || 'TEXTAREA' === $name ) {
                if ( $append_text( $tags->get_modifiable_text() ) ) {
                    return $excerpt;
                }
            }
        }
    }

    return $excerpt;
}
