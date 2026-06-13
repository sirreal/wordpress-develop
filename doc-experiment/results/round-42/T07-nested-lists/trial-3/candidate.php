<?php
function mark_nested_lists( string $html ): string {
    $scanner = WP_HTML_Processor::create_fragment( $html );
    if ( null === $scanner ) {
        return $html;
    }

    while ( $scanner->next_tag() ) {
        $tag = $scanner->get_tag();
        if ( 'UL' !== $tag && 'OL' !== $tag ) {
            continue;
        }

        $breadcrumbs = $scanner->get_breadcrumbs();
        $is_nested   = false;
        $last_index  = count( $breadcrumbs ) - 1;

        for ( $i = 0; $i < $last_index; $i++ ) {
            if ( 'UL' === $breadcrumbs[ $i ] || 'OL' === $breadcrumbs[ $i ] ) {
                $is_nested = true;
                break;
            }
        }
    }

    if ( $scanner->paused_at_incomplete_token() || null !== $scanner->get_last_error() ) {
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
        $is_nested   = false;
        $last_index  = count( $breadcrumbs ) - 1;

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

    if ( $processor->paused_at_incomplete_token() || null !== $processor->get_last_error() ) {
        return $html;
    }

    return $processor->get_updated_html();
}
