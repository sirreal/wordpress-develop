<?php
function extract_toc( string $html ): array {
    $processor = WP_HTML_Processor::create_fragment( $html );
    if ( null === $processor ) {
        return array();
    }

    $toc             = array();
    $current_heading = null;
    $heading_levels  = array(
        'H1' => 1,
        'H2' => 2,
        'H3' => 3,
        'H4' => 4,
        'H5' => 5,
        'H6' => 6,
    );

    while ( $processor->next_token() ) {
        $token_name = $processor->get_token_name();

        if ( null === $current_heading ) {
            if ( null !== $token_name && isset( $heading_levels[ $token_name ] ) && ! $processor->is_tag_closer() ) {
                $current_heading = array(
                    'level' => $heading_levels[ $token_name ],
                    'text'  => '',
                );
            }

            continue;
        }

        if ( '#text' === $processor->get_token_type() ) {
            $current_heading['text'] .= $processor->get_modifiable_text();
            continue;
        }

        if ( $processor->is_tag_closer() && $token_name === 'H' . $current_heading['level'] ) {
            $toc[]           = $current_heading;
            $current_heading = null;
        }
    }

    return $toc;
}
