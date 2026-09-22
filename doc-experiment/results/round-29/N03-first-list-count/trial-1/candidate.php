<?php
function add_first_list_item_count( string $html ): string {
    $processor = WP_HTML_Processor::create_fragment( $html );
    if ( null === $processor ) {
        return $html;
    }

    $found_list = false;
    while ( $processor->next_tag() ) {
        $tag = $processor->get_tag();
        if ( 'UL' === $tag || 'OL' === $tag ) {
            $found_list = true;
            break;
        }
    }

    if ( ! $found_list ) {
        return $html;
    }

    if ( ! $processor->set_bookmark( 'first-list-opener' ) ) {
        return $html;
    }

    $list_depth     = $processor->get_current_depth();
    $direct_li_count = 0;
    $scan_completed = false;

    while ( $processor->next_token() ) {
        if ( $processor->get_current_depth() < $list_depth ) {
            $scan_completed = true;
            break;
        }

        if (
            'LI' === $processor->get_tag() &&
            ! $processor->is_tag_closer() &&
            $processor->get_current_depth() === $list_depth + 1
        ) {
            $direct_li_count++;
        }
    }

    if (
        ! $scan_completed ||
        $processor->paused_at_incomplete_token() ||
        null !== $processor->get_last_error() ||
        ! $processor->seek( 'first-list-opener' )
    ) {
        $processor->release_bookmark( 'first-list-opener' );
        return $html;
    }

    $processor->set_attribute( 'data-item-count', (string) $direct_li_count );
    $updated_html = $processor->get_updated_html();
    $processor->release_bookmark( 'first-list-opener' );

    return $updated_html;
}
