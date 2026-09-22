<?php

function remove_empty_paragraphs( string $html ): string {
    $processor = WP_HTML_Processor::create_fragment( $html );
    if ( null === $processor ) {
        return $html;
    }

    $output = '';

    while ( $processor->next_token() ) {
        if ( 'P' === $processor->get_tag() && ! $processor->is_tag_closer() ) {
            if ( ! $processor->set_bookmark( 'p-start' ) ) {
                return $html;
            }

            $p_depth     = $processor->get_current_depth();
            $has_content = false;

            while ( $processor->next_token() && $processor->get_current_depth() >= $p_depth ) {
                $has_content = true;
            }

            if ( null !== $processor->get_last_error() || $processor->paused_at_incomplete_token() ) {
                return $html;
            }

            if ( $has_content ) {
                if ( ! $processor->seek( 'p-start' ) ) {
                    return $html;
                }

                do {
                    $output .= $processor->serialize_token();
                } while ( $processor->next_token() && $processor->get_current_depth() >= $p_depth );

                $output .= $processor->serialize_token();
            }

            $processor->release_bookmark( 'p-start' );
            continue;
        }

        $output .= $processor->serialize_token();
    }

    if ( null !== $processor->get_last_error() || $processor->paused_at_incomplete_token() ) {
        return $html;
    }

    return $output;
}
