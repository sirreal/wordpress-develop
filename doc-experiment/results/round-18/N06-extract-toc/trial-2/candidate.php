<?php
function extract_toc( string $html ): array {
    $processor = WP_HTML_Processor::create_fragment( $html );

    if ( null === $processor ) {
        return array();
    }

    $toc            = array();
    $current_level  = null;
    $current_tag    = null;
    $current_text   = '';

    while ( $processor->next_token() ) {
        $token_type = $processor->get_token_type();

        if ( '#tag' === $token_type ) {
            $tag = $processor->get_tag();

            if ( null !== $current_tag && $processor->is_tag_closer() && $tag === $current_tag ) {
                $toc[] = array(
                    'level' => $current_level,
                    'text'  => $current_text,
                );

                $current_level = null;
                $current_tag   = null;
                $current_text  = '';
                continue;
            }

            if ( ! $processor->is_tag_closer() && null !== $tag && preg_match( '/^H([1-6])$/', $tag, $matches ) ) {
                $current_level = (int) $matches[1];
                $current_tag   = $tag;
                $current_text  = '';
                continue;
            }

            if ( null !== $current_tag && ! $processor->is_tag_closer() ) {
                $current_text .= $processor->get_modifiable_text();
            }

            continue;
        }

        if ( null !== $current_tag && '#text' === $token_type ) {
            $current_text .= $processor->get_modifiable_text();
        }
    }

    return $toc;
}
