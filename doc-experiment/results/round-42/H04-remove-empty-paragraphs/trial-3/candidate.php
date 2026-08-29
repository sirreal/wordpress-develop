<?php

function remove_empty_paragraphs( string $html ): string {
    $processor = WP_HTML_Processor::create_fragment( $html );
    if ( null === $processor ) {
        return $html;
    }

    $output = '';
    $paragraph_stack = array();

    while ( $processor->next_token() ) {
        $is_p_tag = '#tag' === $processor->get_token_type() && 'P' === $processor->get_tag();

        if ( $is_p_tag && ! $processor->is_tag_closer() ) {
            $count = count( $paragraph_stack );
            for ( $i = 0; $i < $count; $i++ ) {
                $paragraph_stack[ $i ]['has_content'] = true;
                if ( ! $paragraph_stack[ $i ]['emitted'] ) {
                    $output .= $paragraph_stack[ $i ]['opener'];
                    $paragraph_stack[ $i ]['emitted'] = true;
                }
            }

            $paragraph_stack[] = array(
                'opener'      => $processor->serialize_token(),
                'has_content' => false,
                'emitted'     => false,
            );
            continue;
        }

        if ( $is_p_tag && $processor->is_tag_closer() ) {
            if ( 0 === count( $paragraph_stack ) ) {
                $output .= $processor->serialize_token();
                continue;
            }

            $paragraph = array_pop( $paragraph_stack );
            if ( $paragraph['has_content'] ) {
                if ( ! $paragraph['emitted'] ) {
                    $output .= $paragraph['opener'];
                }
                $output .= $processor->serialize_token();
            }
            continue;
        }

        $count = count( $paragraph_stack );
        for ( $i = 0; $i < $count; $i++ ) {
            $paragraph_stack[ $i ]['has_content'] = true;
            if ( ! $paragraph_stack[ $i ]['emitted'] ) {
                $output .= $paragraph_stack[ $i ]['opener'];
                $paragraph_stack[ $i ]['emitted'] = true;
            }
        }

        $output .= $processor->serialize_token();
    }

    if ( $processor->paused_at_incomplete_token() || null !== $processor->get_last_error() ) {
        return $html;
    }

    return $output;
}
