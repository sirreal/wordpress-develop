<?php

function remove_empty_paragraphs( string $html ): string {
    $processor = WP_HTML_Processor::create_fragment( $html );
    if ( null === $processor ) {
        return $html;
    }

    $output          = '';
    $skip_p_depth    = null;
    $pending_empty_p = false;
    $pending_p_depth = null;

    while ( $processor->next_token() ) {
        $token_type = $processor->get_token_type();
        $tag_name   = $processor->get_tag();
        $depth      = $processor->get_current_depth();

        if ( null !== $skip_p_depth ) {
            if ( 'P' === $tag_name && $processor->is_tag_closer() && $depth === $skip_p_depth - 1 ) {
                $skip_p_depth = null;
            }
            continue;
        }

        if ( $pending_empty_p ) {
            if ( 'P' === $tag_name && $processor->is_tag_closer() && $depth === $pending_p_depth - 1 ) {
                $pending_empty_p = false;
                $pending_p_depth = null;
                continue;
            }

            $output .= '<p>';
            $pending_empty_p = false;
            $pending_p_depth = null;
        }

        if ( '#tag' === $token_type && 'P' === $tag_name && ! $processor->is_tag_closer() ) {
            $pending_empty_p = true;
            $pending_p_depth = $depth;
            continue;
        }

        $output .= $processor->serialize_token();
    }

    if ( $processor->paused_at_incomplete_token() || null !== $processor->get_last_error() ) {
        return $html;
    }

    if ( $pending_empty_p ) {
        $output .= '<p>';
    }

    return $output;
}
