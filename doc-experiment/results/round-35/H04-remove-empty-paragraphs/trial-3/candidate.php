<?php
function remove_empty_paragraphs( string $html ): string {
    $processor = WP_HTML_Processor::create_fragment( $html );
    if ( null === $processor ) {
        return $html;
    }

    $output    = '';
    $pending_p = null;

    while ( $processor->next_token() ) {
        if ( null !== $pending_p ) {
            $is_empty_paragraph_closer =
                '#tag' === $processor->get_token_type() &&
                'P' === $processor->get_tag() &&
                $processor->is_tag_closer() &&
                $processor->get_current_depth() < $pending_p['depth'];

            if ( $is_empty_paragraph_closer ) {
                $pending_p = null;
                continue;
            }

            $output   .= $pending_p['opener'];
            $pending_p = null;
        }

        if (
            '#tag' === $processor->get_token_type() &&
            'P' === $processor->get_tag() &&
            ! $processor->is_tag_closer()
        ) {
            $pending_p = array(
                'depth'  => $processor->get_current_depth(),
                'opener' => $processor->serialize_token(),
            );
            continue;
        }

        $output .= $processor->serialize_token();
    }

    if ( $processor->paused_at_incomplete_token() || null !== $processor->get_last_error() ) {
        return $html;
    }

    if ( null !== $pending_p ) {
        return $html;
    }

    return $output;
}
