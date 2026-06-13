<?php
function html_text_excerpt( string $html, int $max_codepoints ): string {
    if ( $max_codepoints <= 0 ) {
        return '';
    }

    $processor = WP_HTML_Processor::create_fragment( $html );
    if ( null === $processor ) {
        return '';
    }

    $excerpt   = '';
    $remaining = $max_codepoints;

    while ( $remaining > 0 && $processor->next_token() ) {
        $chunk = '';

        if ( '#text' === $processor->get_token_type() ) {
            $chunk = $processor->get_modifiable_text();
        } elseif ( '#tag' === $processor->get_token_type() && ! $processor->is_tag_closer() ) {
            $tag_name = $processor->get_tag();
            if ( 'TEXTAREA' === $tag_name || 'TITLE' === $tag_name ) {
                $chunk = $processor->get_modifiable_text();
            }
        }

        if ( '' === $chunk ) {
            continue;
        }

        if ( 1 !== preg_match_all( '/./us', $chunk, $matches ) ) {
            continue;
        }

        $codepoints = $matches[0];
        $count      = count( $codepoints );

        if ( $count <= $remaining ) {
            $excerpt .= $chunk;
            $remaining -= $count;
            continue;
        }

        $excerpt .= implode( '', array_slice( $codepoints, 0, $remaining ) );
        break;
    }

    return $excerpt;
}
