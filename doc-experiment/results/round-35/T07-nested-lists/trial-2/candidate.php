<?php
function mark_nested_lists( string $html ): string {
    $scan = WP_HTML_Processor::create_fragment( $html );
    if ( null === $scan ) {
        return $html;
    }

    $found_nested_list = false;

    while ( $scan->next_tag() ) {
        $tag = $scan->get_tag();
        if ( 'UL' !== $tag && 'OL' !== $tag ) {
            continue;
        }

        $breadcrumbs      = $scan->get_breadcrumbs();
        $breadcrumb_count = count( $breadcrumbs );

        for ( $i = 0; $i < $breadcrumb_count - 1; $i++ ) {
            if ( 'UL' === $breadcrumbs[ $i ] || 'OL' === $breadcrumbs[ $i ] ) {
                $found_nested_list = true;
                break 2;
            }
        }
    }

    if ( $scan->paused_at_incomplete_token() || null !== $scan->get_last_error() ) {
        return $html;
    }

    if ( ! $found_nested_list ) {
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

        $breadcrumbs      = $processor->get_breadcrumbs();
        $breadcrumb_count = count( $breadcrumbs );
        $is_nested_list   = false;

        for ( $i = 0; $i < $breadcrumb_count - 1; $i++ ) {
            if ( 'UL' === $breadcrumbs[ $i ] || 'OL' === $breadcrumbs[ $i ] ) {
                $is_nested_list = true;
                break;
            }
        }

        if ( $is_nested_list ) {
            $processor->add_class( 'nested-list' );
        }
    }

    if ( $processor->paused_at_incomplete_token() || null !== $processor->get_last_error() ) {
        return $html;
    }

    return $processor->get_updated_html();
}
