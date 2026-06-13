<?php
function mark_nested_lists( string $html ): string {
    $processor = WP_HTML_Processor::create_fragment( $html );

    if ( null === $processor ) {
        return $html;
    }

    while ( $processor->next_tag() ) {
        $tag_name = $processor->get_tag();

        if ( 'UL' !== $tag_name && 'OL' !== $tag_name ) {
            continue;
        }

        $breadcrumbs = $processor->get_breadcrumbs();
        $last_index  = count( $breadcrumbs ) - 1;
        $is_nested   = false;

        for ( $i = 0; $i < $last_index; $i++ ) {
            if ( 'UL' === $breadcrumbs[ $i ] || 'OL' === $breadcrumbs[ $i ] ) {
                $is_nested = true;
                break;
            }
        }

        if ( $is_nested ) {
            $processor->add_class( 'nested-list' );
        }
    }

    if ( null !== $processor->get_last_error() ) {
        return $html;
    }

    return $processor->get_updated_html();
}
