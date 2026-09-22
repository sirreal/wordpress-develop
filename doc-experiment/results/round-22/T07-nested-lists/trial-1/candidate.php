<?php
function mark_nested_lists( string $html ): string {
    $processor = WP_HTML_Processor::create_fragment( $html );

    if ( null === $processor ) {
        return $html;
    }

    while ( $processor->next_tag( array( 'tag_name' => null ) ) ) {
        $tag = $processor->get_tag();
        if ( 'UL' !== $tag && 'OL' !== $tag ) {
            continue;
        }

        $breadcrumbs = $processor->get_breadcrumbs();
        $depth       = count( $breadcrumbs );
        $is_nested   = false;

        for ( $i = 0; $i < $depth - 1; $i++ ) {
            if ( 'UL' === $breadcrumbs[ $i ] || 'OL' === $breadcrumbs[ $i ] ) {
                $is_nested = true;
                break;
            }
        }

        if ( $is_nested ) {
            $processor->add_class( 'nested-list' );
        }
    }

    if ( $processor->paused_at_incomplete_token() || null !== $processor->get_last_error() ) {
        return $html;
    }

    return $processor->get_updated_html();
}
