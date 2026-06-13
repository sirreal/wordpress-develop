<?php

function add_first_list_item_count( string $html ): string {
    $processor = WP_HTML_Processor::create_fragment( $html );
    if ( null === $processor ) {
        return $html;
    }

    while ( $processor->next_tag() ) {
        $tag_name = $processor->get_tag();
        if ( 'UL' !== $tag_name && 'OL' !== $tag_name ) {
            continue;
        }

        if ( ! $processor->set_bookmark( 'first-list' ) ) {
            return $html;
        }

        $list_depth     = $processor->get_current_depth();
        $item_count     = 0;
        $scan_completed = false;

        while ( $processor->next_token() ) {
            if ( $processor->get_current_depth() < $list_depth ) {
                $scan_completed = true;
                break;
            }

            if (
                '#tag' === $processor->get_token_type() &&
                ! $processor->is_tag_closer() &&
                'LI' === $processor->get_tag() &&
                $processor->get_current_depth() === $list_depth + 1
            ) {
                $item_count++;
            }
        }

        if (
            ! $scan_completed ||
            $processor->paused_at_incomplete_token() ||
            null !== $processor->get_last_error() ||
            ! $processor->seek( 'first-list' )
        ) {
            $processor->release_bookmark( 'first-list' );
            return $html;
        }

        if ( ! $processor->set_attribute( 'data-item-count', (string) $item_count ) ) {
            $processor->release_bookmark( 'first-list' );
            return $html;
        }

        $updated_html = $processor->get_updated_html();
        $processor->release_bookmark( 'first-list' );
        return $updated_html;
    }

    return $html;
}
