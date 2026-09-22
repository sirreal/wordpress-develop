<?php
function collect_links( string $html ): array {
    $processor = WP_HTML_Processor::create_fragment( $html );
    if ( null === $processor ) {
        return array();
    }

    $links = array();

    while ( $processor->next_tag( 'A' ) ) {
        $href = $processor->get_attribute( 'href' );
        if ( ! is_string( $href ) ) {
            continue;
        }

        $depth = $processor->get_current_depth();
        $text  = '';

        while ( $processor->next_token() && $processor->get_current_depth() >= $depth ) {
            if ( '#text' === $processor->get_token_type() ) {
                $text .= $processor->get_modifiable_text();
            }
        }

        $links[] = array(
            'href' => $href,
            'text' => $text,
        );
    }

    if ( $processor->paused_at_incomplete_token() || null !== $processor->get_last_error() ) {
        return array();
    }

    return $links;
}
