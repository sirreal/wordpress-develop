<?php
function remove_empty_paragraphs( string $html ): string {
    $processor = WP_HTML_Processor::create_fragment( $html );
    if ( null === $processor ) {
        return $html;
    }

    $output            = '';
    $pending_p_depth   = null;
    $pending_p_tokens  = '';
    $pending_p_is_empty = false;

    while ( $processor->next_token() ) {
        if ( null !== $pending_p_depth ) {
            if ( 'P' === $processor->get_tag() && $processor->is_tag_closer() && $processor->get_current_depth() < $pending_p_depth ) {
                if ( $pending_p_is_empty ) {
                    $pending_p_depth    = null;
                    $pending_p_tokens   = '';
                    $pending_p_is_empty = false;
                    continue;
                }

                $output .= $pending_p_tokens;
                $output .= $processor->serialize_token();

                $pending_p_depth    = null;
                $pending_p_tokens   = '';
                $pending_p_is_empty = false;
                continue;
            }

            $pending_p_is_empty = false;
            $pending_p_tokens  .= $processor->serialize_token();
            continue;
        }

        if ( 'P' === $processor->get_tag() && ! $processor->is_tag_closer() ) {
            $pending_p_depth    = $processor->get_current_depth();
            $pending_p_tokens   = $processor->serialize_token();
            $pending_p_is_empty = true;
            continue;
        }

        $output .= $processor->serialize_token();
    }

    if ( null !== $pending_p_depth || $processor->paused_at_incomplete_token() || null !== $processor->get_last_error() ) {
        return $html;
    }

    return $output;
}
