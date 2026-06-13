<?php
function extract_toc( string $html ): array {
    $processor = WP_HTML_Processor::create_fragment( $html );
    if ( null === $processor ) {
        return array();
    }

    $toc                 = array();
    $current_heading_tag = null;
    $current_index       = null;

    while ( $processor->next_token() ) {
        $token_type = $processor->get_token_type();

        if ( '#tag' === $token_type ) {
            $tag = $processor->get_tag();

            if ( null === $tag ) {
                continue;
            }

            if ( $processor->is_tag_closer() ) {
                if ( null !== $current_heading_tag && $tag === $current_heading_tag ) {
                    $current_heading_tag = null;
                    $current_index       = null;
                }

                continue;
            }

            switch ( $tag ) {
                case 'H1':
                case 'H2':
                case 'H3':
                case 'H4':
                case 'H5':
                case 'H6':
                    $toc[] = array(
                        'level' => (int) substr( $tag, 1 ),
                        'text'  => '',
                    );

                    $current_index       = count( $toc ) - 1;
                    $current_heading_tag = $tag;
                    break;

                case 'SCRIPT':
                case 'STYLE':
                case 'TEXTAREA':
                case 'TITLE':
                    if ( null !== $current_index ) {
                        $toc[ $current_index ]['text'] .= $processor->get_modifiable_text();
                    }
                    break;
            }

            continue;
        }

        if ( null !== $current_index && '#text' === $token_type ) {
            $toc[ $current_index ]['text'] .= $processor->get_modifiable_text();
        }
    }

    return $toc;
}
