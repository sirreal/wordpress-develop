<?php
function mark_keyword( string $html, string $keyword ): string {
    $processor = WP_HTML_Processor::create_fragment( $html );

    if ( null === $processor ) {
        $normalized = WP_HTML_Processor::normalize( $html );
        return null === $normalized ? $html : $normalized;
    }

    $output = '';

    while ( $processor->next_token() ) {
        if ( '#text' === $processor->get_token_type() ) {
            $text = $processor->get_modifiable_text();

            if ( false !== strpos( $text, $keyword ) ) {
                $normalized_text = WP_HTML_Processor::normalize( $text );
                $output          .= '<mark>' . ( null === $normalized_text ? '' : $normalized_text ) . '</mark>';
                continue;
            }
        }

        $output .= $processor->serialize_token();
    }

    if ( null !== $processor->get_last_error() ) {
        $normalized = WP_HTML_Processor::normalize( $html );
        return null === $normalized ? $html : $normalized;
    }

    return $output;
}
