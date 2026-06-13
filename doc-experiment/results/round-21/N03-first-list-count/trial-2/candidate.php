<?php
function add_first_list_item_count( string $html ): string {
    $processor = WP_HTML_Processor::create_fragment( $html );

    if ( null === $processor ) {
        return $html;
    }

    if ( ! $processor->next_tag( array( 'tag_name' => 'UL' ) ) ) {
        if ( ! $processor->next_tag( array( 'tag_name' => 'OL' ) ) ) {
            return $html;
        }
        $first_list_tag = 'OL';
    } else {
        $first_list_tag = 'UL';
    }

    $list_bookmark = 'first-list';
    if ( ! $processor->set_bookmark( $list_bookmark ) ) {
        return $html;
    }

    $list_depth = $processor->get_current_depth();
    $count      = 0;

    while ( $processor->next_token() ) {
        if ( $processor->get_current_depth() < $list_depth ) {
            break;
        }

        if (
            '#tag' === $processor->get_token_type() &&
            ! $processor->is_tag_closer() &&
            'LI' === $processor->get_tag() &&
            $processor->get_current_depth() === $list_depth + 1
        ) {
            $count++;
        }
    }

    $scan_finished_cleanly =
        ! $processor->paused_at_incomplete_token() &&
        null === $processor->get_last_error() &&
        '#tag' === $processor->get_token_type() &&
        $processor->is_tag_closer() &&
        $first_list_tag === $processor->get_tag();

    if ( ! $scan_finished_cleanly ) {
        $processor->release_bookmark( $list_bookmark );
        return $html;
    }

    if ( ! $processor->seek( $list_bookmark ) ) {
        $processor->release_bookmark( $list_bookmark );
        return $html;
    }

    $processor->set_attribute( 'data-item-count', (string) $count );
    $processor->release_bookmark( $list_bookmark );

    return $processor->get_updated_html();
}
