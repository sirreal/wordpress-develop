<?php

function remove_empty_paragraphs( string $html ): string {
    $processor = WP_HTML_Processor::create_fragment( $html );
    if ( null === $processor ) {
        return $html;
    }

    $output   = '';
    $stack    = array();

    while ( $processor->next_token() ) {
        $is_p_tag = '#tag' === $processor->get_token_type() && 'P' === $processor->get_tag();
        $token    = $processor->serialize_token();

        if ( $is_p_tag && ! $processor->is_tag_closer() ) {
            $stack[] = array(
                'buffer'      => $token,
                'has_content' => false,
            );
            continue;
        }

        if ( $is_p_tag && $processor->is_tag_closer() && ! empty( $stack ) ) {
            $current = array_pop( $stack );
            $current['buffer'] .= $token;

            if ( ! $current['has_content'] ) {
                continue;
            }

            if ( ! empty( $stack ) ) {
                $parent_index = count( $stack ) - 1;
                $stack[ $parent_index ]['has_content'] = true;
                $stack[ $parent_index ]['buffer']     .= $current['buffer'];
            } else {
                $output .= $current['buffer'];
            }

            continue;
        }

        if ( ! empty( $stack ) ) {
            $current_index = count( $stack ) - 1;
            if ( '' !== $token ) {
                $stack[ $current_index ]['has_content'] = true;
            }
            $stack[ $current_index ]['buffer'] .= $token;
            continue;
        }

        $output .= $token;
    }

    if ( $processor->paused_at_incomplete_token() || null !== $processor->get_last_error() || ! empty( $stack ) ) {
        return $html;
    }

    return $output;
}
