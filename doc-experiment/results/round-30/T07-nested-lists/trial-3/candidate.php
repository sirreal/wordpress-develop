<?php
function mark_nested_lists( string $html ): string {
    $probe = WP_HTML_Processor::create_fragment( $html );
    if ( null === $probe ) {
        return $html;
    }

    while ( $probe->next_tag() ) {
        continue;
    }

    if ( $probe->paused_at_incomplete_token() || null !== $probe->get_last_error() ) {
        return $html;
    }

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

        if ( in_array( 'UL', $breadcrumbs, true ) || in_array( 'OL', $breadcrumbs, true ) ) {
            $processor->add_class( 'nested-list' );
        }
    }

    return $processor->get_updated_html();
}
