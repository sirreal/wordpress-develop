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
            if ( 'P' === $processor->get_tag() && $processor->is_tag_closer() ) {
                if ( $pending_p['has_content'] ) {
                    $pending_p['buffer'] .= $processor->serialize_token();
                    $output .= $pending_p['buffer'];
                }

                $pending_p = null;
                continue;
            }

            $pending_p['has_content'] = true;
            $pending_p['buffer']     .= $processor->serialize_token();
            continue;
        }

        if ( 'P' === $processor->get_tag() && ! $processor->is_tag_closer() ) {
            $pending_p = array(
                'buffer'      => $processor->serialize_token(),
                'has_content' => false,
            );
            continue;
        }

        $output .= $processor->serialize_token();
    }

    if ( null !== $processor->get_last_error() || $processor->paused_at_incomplete_token() || null !== $pending_p ) {
        return $html;
    }

    return $output;
}
