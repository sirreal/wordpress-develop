<?php
function mark_nested_lists( string $html ): string {
    $validator = WP_HTML_Processor::create_fragment( $html );
    if ( null === $validator ) {
        return $html;
    }

    while ( $validator->next_tag() ) {
        continue;
    }

    if ( $validator->paused_at_incomplete_token() || null !== $validator->get_last_error() ) {
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

        foreach ( $breadcrumbs as $ancestor ) {
            if ( 'UL' === $ancestor || 'OL' === $ancestor ) {
                $processor->add_class( 'nested-list' );
                break;
            }
        }
    }

    return $processor->get_updated_html();
}
