<?php
function remove_empty_paragraphs( string $html ): string {
    $processor = WP_HTML_Processor::create_fragment( $html );
    if ( null === $processor ) {
        return $html;
    }

    $output = '';
    $p_stack = array();

    while ( $processor->next_token() ) {
        $is_html_p = '#tag' === $processor->get_token_type()
            && 'html' === $processor->get_namespace()
            && 'P' === $processor->get_tag();

        if ( $is_html_p && ! $processor->is_tag_closer() ) {
            if ( ! empty( $p_stack ) ) {
                $p_stack[ count( $p_stack ) - 1 ]['has_content'] = true;
            }

            $p_stack[] = array(
                'buffer'      => $processor->serialize_token(),
                'has_content' => false,
            );
            continue;
        }

        if ( $is_html_p && $processor->is_tag_closer() && ! empty( $p_stack ) ) {
            $frame = array_pop( $p_stack );

            if ( $frame['has_content'] ) {
                $serialized = $frame['buffer'] . $processor->serialize_token();

                if ( ! empty( $p_stack ) ) {
                    $p_stack[ count( $p_stack ) - 1 ]['buffer'] .= $serialized;
                } else {
                    $output .= $serialized;
                }
            }

            continue;
        }

        $serialized = $processor->serialize_token();

        if ( ! empty( $p_stack ) ) {
            $p_stack[ count( $p_stack ) - 1 ]['has_content'] = true;
            $p_stack[ count( $p_stack ) - 1 ]['buffer']     .= $serialized;
        } else {
            $output .= $serialized;
        }
    }

    if ( ! empty( $p_stack ) || $processor->paused_at_incomplete_token() || null !== $processor->get_last_error() ) {
        return $html;
    }

    return $output;
}
