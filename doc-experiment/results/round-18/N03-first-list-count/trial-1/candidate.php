<?php
function add_first_list_item_count( string $html ): string {
    $processor = WP_HTML_Processor::create_fragment( $html );
    if ( null === $processor ) {
        return $html;
    }

    $found_list = false;
    $list_depth = 0;

    while ( $processor->next_tag() ) {
        $tag = $processor->get_tag();
        if ( 'UL' === $tag || 'OL' === $tag ) {
            if ( ! $processor->set_bookmark( 'first-list' ) ) {
                return $html;
            }

            $found_list = true;
            $list_depth = $processor->get_current_depth();
            break;
        }
    }

    if ( ! $found_list ) {
        return $html;
    }

    $count     = 0;
    $completed = false;

    while ( $processor->next_token() ) {
        if ( $processor->get_current_depth() < $list_depth ) {
            $completed = true;
            break;
        }

        if (
            'LI' === $processor->get_tag() &&
            ! $processor->is_tag_closer() &&
            $processor->get_current_depth() === $list_depth + 1
        ) {
            $count++;
        }
    }

    if ( ! $completed ) {
        return $html;
    }

    if ( ! $processor->seek( 'first-list' ) ) {
        return $html;
    }

    $processor->set_attribute( 'data-item-count', (string) $count );
    $processor->release_bookmark( 'first-list' );

    return $processor->get_updated_html();
}
