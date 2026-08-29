<?php

function add_first_list_item_count( string $html ): string {
    if ( ! class_exists( 'WP_HTML_Processor' ) ) {
        return $html;
    }

    try {
        $processor = WP_HTML_Processor::create_fragment( $html );
        if ( null === $processor ) {
            return $html;
        }

        while ( $processor->next_tag() ) {
            $tag_name = $processor->get_tag();

            if ( 'UL' !== $tag_name && 'OL' !== $tag_name ) {
                continue;
            }

            $list_depth = $processor->get_current_depth();
            if ( ! $processor->set_bookmark( 'first-list-opener' ) ) {
                return $html;
            }

            $item_count    = 0;
            $scan_finished = false;

            while ( $processor->next_token() ) {
                if ( $processor->get_current_depth() < $list_depth ) {
                    $scan_finished = true;
                    break;
                }

                if (
                    '#tag' === $processor->get_token_type() &&
                    ! $processor->is_tag_closer() &&
                    'LI' === $processor->get_tag() &&
                    $list_depth + 1 === $processor->get_current_depth()
                ) {
                    $item_count++;
                }
            }

            if (
                ! $scan_finished ||
                $processor->paused_at_incomplete_token() ||
                null !== $processor->get_last_error()
            ) {
                return $html;
            }

            if ( ! $processor->seek( 'first-list-opener' ) ) {
                return $html;
            }

            $processor->set_attribute( 'data-item-count', (string) $item_count );
            $processor->release_bookmark( 'first-list-opener' );

            return $processor->get_updated_html();
        }
    } catch ( Exception $e ) {
        return $html;
    }

    return $html;
}
