<?php
function extract_toc( string $html ): array {
    $processor = WP_HTML_Processor::create_fragment( $html );
    if ( null === $processor ) {
        return array();
    }

    $toc           = array();
    $heading_level = null;
    $heading_tag   = null;
    $heading_text  = '';

    while ( $processor->next_token() ) {
        $token_type = $processor->get_token_type();
        $token_name = $processor->get_token_name();

        if ( null !== $heading_level ) {
            if ( '#text' === $token_type ) {
                $heading_text .= $processor->get_modifiable_text();
                continue;
            }

            if ( '#tag' === $token_type && $processor->is_tag_closer() && $token_name === $heading_tag ) {
                $toc[] = array(
                    'level' => $heading_level,
                    'text'  => $heading_text,
                );
                $heading_level = null;
                $heading_tag   = null;
                $heading_text  = '';
                continue;
            }
        }

        if ( '#tag' !== $token_type || $processor->is_tag_closer() ) {
            continue;
        }

        if ( preg_match( '/^H([1-6])$/', $token_name, $matches ) ) {
            $heading_level = (int) $matches[1];
            $heading_tag   = $token_name;
            $heading_text  = '';
        }
    }

    return $toc;
}
