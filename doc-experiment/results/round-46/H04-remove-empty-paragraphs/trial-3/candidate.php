<?php

function remove_empty_paragraphs( string $html ): string {
    $processor = WP_HTML_Processor::create_fragment( $html );
    if ( null === $processor ) {
        return $html;
    }

    $output          = '';
    $pending_p_depth = null;
    $pending_p_html  = '';

    while ( $processor->next_token() ) {
        if ( null !== $pending_p_depth ) {
            if ( $processor->get_current_depth() < $pending_p_depth ) {
                // This is the pending paragraph's closer, so the paragraph was empty.
                $pending_p_depth = null;
                $pending_p_html  = '';
                continue;
            }

            $output         .= $pending_p_html;
            $pending_p_depth = null;
            $pending_p_html  = '';
        }

        if ( 'P' === $processor->get_tag() && ! $processor->is_tag_closer() ) {
            $pending_p_depth = $processor->get_current_depth();
            $pending_p_html  = $processor->serialize_token();
            continue;
        }

        $output .= $processor->serialize_token();
    }

    if ( null !== $pending_p_depth ) {
        $output .= $pending_p_html;
    }

    if ( $processor->paused_at_incomplete_token() || null !== $processor->get_last_error() ) {
        return $html;
    }

    return $output;
}
