<?php
function extract_toc( string $html ): array {
    $processor = WP_HTML_Processor::create_fragment( $html );

    if ( null === $processor ) {
        return array();
    }

    $toc             = array();
    $current_heading = null;
    $heading_tag     = null;

    while ( $processor->next_token() ) {
        $token_name = $processor->get_token_name();

        if ( null !== $current_heading ) {
            if ( '#text' === $processor->get_token_type() ) {
                $current_heading['text'] .= $processor->get_modifiable_text();
                continue;
            }

            if ( null !== $token_name && ! $processor->is_tag_closer() ) {
                $current_heading['text'] .= $processor->get_modifiable_text();
            }

            if ( $processor->is_tag_closer() && $token_name === $heading_tag ) {
                $toc[]           = $current_heading;
                $current_heading = null;
                $heading_tag     = null;
                continue;
            }
        }

        if ( null === $token_name || $processor->is_tag_closer() ) {
            continue;
        }

        if ( preg_match( '/^H([1-6])$/', $token_name, $matches ) ) {
            $current_heading = array(
                'level' => (int) $matches[1],
                'text'  => '',
            );
            $heading_tag = $token_name;
        }
    }

    if ( null !== $current_heading ) {
        $toc[] = $current_heading;
    }

    return $toc;
}
