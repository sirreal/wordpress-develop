<?php

function mark_nested_lists( string $html ): string {
    $processor = WP_HTML_Processor::create_fragment( $html );

    if ( null === $processor ) {
        return $html;
    }

    $open_list_depth = 0;

    while ( $processor->next_tag( array( 'tag_closers' => 'visit' ) ) ) {
        $tag = $processor->get_tag();

        if ( 'UL' !== $tag && 'OL' !== $tag ) {
            continue;
        }

        if ( $processor->is_tag_closer() ) {
            if ( $open_list_depth > 0 ) {
                $open_list_depth--;
            }

            continue;
        }

        if ( $open_list_depth > 0 ) {
            $processor->add_class( 'nested-list' );
        }

        $open_list_depth++;
    }

    if ( null !== $processor->get_last_error() ) {
        return $html;
    }

    return $processor->get_updated_html();
}
