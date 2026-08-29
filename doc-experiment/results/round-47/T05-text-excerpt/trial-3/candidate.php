<?php
function html_text_excerpt( string $html, int $max_codepoints ): string {
    if ( $max_codepoints <= 0 ) {
        return '';
    }

    $processor = WP_HTML_Processor::create_fragment( $html );
    if ( null === $processor ) {
        return '';
    }

    $text      = '';
    $remaining = $max_codepoints;

    while ( $remaining > 0 && $processor->next_token() ) {
        $chunk = null;

        if ( '#text' === $processor->get_token_type() ) {
            $chunk = $processor->get_modifiable_text();
        } elseif ( '#tag' === $processor->get_token_type() && ! $processor->is_tag_closer() ) {
            $tag_name = $processor->get_tag();
            if ( 'TEXTAREA' === $tag_name || 'TITLE' === $tag_name ) {
                $chunk = $processor->get_modifiable_text();
            }
        }

        if ( null === $chunk || '' === $chunk ) {
            continue;
        }

        if ( function_exists( 'mb_strlen' ) && function_exists( 'mb_substr' ) ) {
            $length = mb_strlen( $chunk, 'UTF-8' );
            if ( $length <= $remaining ) {
                $text      .= $chunk;
                $remaining -= $length;
            } else {
                $text .= mb_substr( $chunk, 0, $remaining, 'UTF-8' );
                break;
            }
        } else {
            preg_match_all( '/./us', $chunk, $matches );
            $codepoints = $matches[0];
            $length     = count( $codepoints );

            if ( $length <= $remaining ) {
                $text      .= $chunk;
                $remaining -= $length;
            } else {
                $text .= implode( '', array_slice( $codepoints, 0, $remaining ) );
                break;
            }
        }
    }

    return $text;
}
