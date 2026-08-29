<?php
function remove_empty_paragraphs( string $html ): string {
    $processor = WP_HTML_Processor::create_fragment( $html );

    if ( null === $processor ) {
        return $html;
    }

    $output            = '';
    $pending_p         = null;
    $pending_p_depth   = null;

    while ( $processor->next_token() ) {
        $is_p_opener = (
            '#tag' === $processor->get_token_type() &&
            'P' === $processor->get_tag() &&
            ! $processor->is_tag_closer()
        );

        if ( $is_p_opener ) {
            if ( null !== $pending_p ) {
                $output .= $pending_p;
            }

            $pending_p       = $processor->serialize_token();
            $pending_p_depth = $processor->get_current_depth();
            continue;
        }

        if ( null !== $pending_p ) {
            $is_pending_p_closer = (
                '#tag' === $processor->get_token_type() &&
                'P' === $processor->get_tag() &&
                $processor->is_tag_closer() &&
                $processor->get_current_depth() < $pending_p_depth
            );

            if ( $is_pending_p_closer ) {
                $pending_p       = null;
                $pending_p_depth = null;
                continue;
            }

            $output .= $pending_p;
            $pending_p       = null;
            $pending_p_depth = null;
        }

        $output .= $processor->serialize_token();
    }

    if (
        null !== $pending_p ||
        $processor->paused_at_incomplete_token() ||
        null !== $processor->get_last_error()
    ) {
        return $html;
    }

    return $output;
}
