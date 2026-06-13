<?php
function remove_empty_paragraphs( string $html ): string {
    $processor = WP_HTML_Processor::create_fragment( $html );
    if ( null === $processor ) {
        return $html;
    }

    $output            = '';
    $pending_p_opener  = '';
    $pending_empty_p   = false;

    while ( $processor->next_token() ) {
        if ( $pending_empty_p ) {
            if ( 'P' === $processor->get_tag() && $processor->is_tag_closer() ) {
                $pending_empty_p  = false;
                $pending_p_opener = '';
                continue;
            }

            $output           .= $pending_p_opener;
            $pending_empty_p   = false;
            $pending_p_opener  = '';
        }

        if ( 'P' === $processor->get_tag() && ! $processor->is_tag_closer() ) {
            $pending_p_opener = $processor->serialize_token();
            $pending_empty_p  = true;
            continue;
        }

        $output .= $processor->serialize_token();
    }

    if ( $processor->paused_at_incomplete_token() || null !== $processor->get_last_error() ) {
        return $html;
    }

    if ( $pending_empty_p ) {
        $output .= $pending_p_opener;
    }

    return $output;
}
