<?php

function remove_empty_paragraphs( string $html ): string {
    $processor = WP_HTML_Processor::create_fragment( $html );
    if ( null === $processor ) {
        return $html;
    }

    $output = '';

    while ( $processor->next_token() ) {
        if ( 'P' === $processor->get_tag() && ! $processor->is_tag_closer() ) {
            $paragraph_depth  = $processor->get_current_depth();
            $opening_token    = $processor->serialize_token();
            $content          = '';
            $has_any_content  = false;

            while ( $processor->next_token() ) {
                if ( $processor->get_current_depth() < $paragraph_depth ) {
                    break;
                }

                $has_any_content = true;
                $content        .= $processor->serialize_token();
            }

            if ( ! $has_any_content ) {
                continue;
            }

            $output .= $opening_token;
            $output .= $content;
            $output .= $processor->serialize_token();
            continue;
        }

        $output .= $processor->serialize_token();
    }

    if ( $processor->paused_at_incomplete_token() || null !== $processor->get_last_error() ) {
        return $html;
    }

    return $output;
}
