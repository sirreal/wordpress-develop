<?php

function remove_empty_paragraphs( string $html ): string {
    $processor = WP_HTML_Processor::create_fragment( $html );
    if ( null === $processor ) {
        return $html;
    }

    $output   = '';
    $p_stack  = array();

    while ( $processor->next_token() ) {
        if ( '#tag' === $processor->get_token_type() && 'P' === $processor->get_tag() ) {
            if ( $processor->is_tag_closer() ) {
                if ( empty( $p_stack ) ) {
                    $serialized = $processor->serialize_token();
                    $output     .= $serialized;
                    continue;
                }

                $frame = array_pop( $p_stack );

                $serialized = '';
                if ( $frame['has_content'] ) {
                    $serialized = $frame['opener'] . $frame['buffer'] . $processor->serialize_token();
                }

                if ( empty( $p_stack ) ) {
                    $output .= $serialized;
                } else {
                    $p_stack[ count( $p_stack ) - 1 ]['buffer'] .= $serialized;
                }

                continue;
            }

            if ( ! empty( $p_stack ) ) {
                $p_stack[ count( $p_stack ) - 1 ]['has_content'] = true;
            }

            $p_stack[] = array(
                'opener'      => $processor->serialize_token(),
                'buffer'      => '',
                'has_content' => false,
            );

            continue;
        }

        $serialized = $processor->serialize_token();

        if ( empty( $p_stack ) ) {
            $output .= $serialized;
        } else {
            $p_stack[ count( $p_stack ) - 1 ]['buffer']      .= $serialized;
            $p_stack[ count( $p_stack ) - 1 ]['has_content'] = true;
        }
    }

    if ( $processor->paused_at_incomplete_token() || null !== $processor->get_last_error() ) {
        return $html;
    }

    return $output;
}
