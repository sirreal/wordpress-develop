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
        $limit       = count( $breadcrumbs ) - 1;

        for ( $i = 0; $i < $limit; $i++ ) {
            if ( 'UL' === $breadcrumbs[ $i ] || 'OL' === $breadcrumbs[ $i ] ) {
                $processor->add_class( 'nested-list' );
                break;
            }
        }
    }

    if ( null !== $processor->get_last_error() ) {
        return $html;
    }

    return $processor->get_updated_html();
}
