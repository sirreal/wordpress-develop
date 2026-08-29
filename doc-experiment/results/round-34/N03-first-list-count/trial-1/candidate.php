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

        $list_depth = $processor->get_current_depth();
        $count      = 0;

        while ( $processor->next_token() && $processor->get_current_depth() >= $list_depth ) {
            if (
                '#tag' === $processor->get_token_type() &&
                ! $processor->is_tag_closer() &&
                'LI' === $processor->get_tag() &&
                $processor->get_current_depth() === $list_depth + 1
            ) {
                $count++;
            }
        }

        $scan_finished_cleanly = ! $processor->paused_at_incomplete_token() && null === $processor->get_last_error();
        if ( ! $scan_finished_cleanly ) {
            $processor->release_bookmark( 'first-list' );
            return $html;
        }

        if ( ! $processor->seek( 'first-list' ) ) {
            $processor->release_bookmark( 'first-list' );
            return $html;
        }

        $processor->set_attribute( 'data-item-count', (string) $count );
        $processor->release_bookmark( 'first-list' );

        return $processor->get_updated_html();
    }

    return $html;
}
