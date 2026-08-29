<?php
function mark_nested_lists( string $html ): string {
    $processor = WP_HTML_Processor::create_fragment( $html );

    if ( null === $processor ) {
        return $html;
    }

    while ( $processor->next_tag() ) {
        $tag = $processor->get_tag();

        if ( 'UL' !== $tag && 'OL' !== $tag ) {
            continue;
        }

        $breadcrumbs = $processor->get_breadcrumbs();
        array_pop( $breadcrumbs );

        foreach ( $breadcrumbs as $ancestor_tag ) {
            if ( 'UL' === $ancestor_tag || 'OL' === $ancestor_tag ) {
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
