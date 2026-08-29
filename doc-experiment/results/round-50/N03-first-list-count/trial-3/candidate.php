<?php
function add_first_list_item_count( string $html ): string {
    $processor = WP_HTML_Processor::create_fragment( $html );
    if ( null === $processor ) {
        return $html;
    }

    while ( $processor->next_tag() ) {
        $tag = $processor->get_tag();
        if ( 'UL' !== $tag && 'OL' !== $tag ) {
            continue;
        }

        if ( ! $processor->set_bookmark( 'first-list' ) ) {
            return $html;
        }

        $list_depth    = $processor->get_current_depth();
        $item_count    = 0;
        $scan_finished = false;

        while ( $processor->next_token() ) {
            if ( $processor->get_current_depth() < $list_depth ) {
                $scan_finished = true;
                break;
            }

            $is_direct_child_li =
                '#tag' === $processor->get_token_type() &&
                ! $processor->is_tag_closer() &&
                'LI' === $processor->get_tag() &&
                $processor->get_current_depth() === $list_depth + 1;

            if ( $is_direct_child_li ) {
                $item_count++;
            }
        }

        $scan_finished_cleanly =
            $scan_finished &&
            ! $processor->paused_at_incomplete_token() &&
            null === $processor->get_last_error();

        if ( ! $scan_finished_cleanly ) {
            $processor->release_bookmark( 'first-list' );
            return $html;
        }

        if ( ! $processor->seek( 'first-list' ) ) {
            $processor->release_bookmark( 'first-list' );
            return $html;
        }

        $processor->set_attribute( 'data-item-count', (string) $item_count );
        $processor->release_bookmark( 'first-list' );

        return $processor->get_updated_html();
    }

    return $html;
}
