<?php

function normalize_or_placeholder( string $html ): string {
    $fallback = '<p>Unsupported HTML</p>';

    if ( ! class_exists( 'WP_HTML_Processor' ) ) {
        return $fallback;
    }

    try {
        $processor = WP_HTML_Processor::create_fragment( $html );
        if ( null === $processor ) {
            return $fallback;
        }

        $normalized = $processor->serialize();
        return null === $normalized ? $fallback : $normalized;
    } catch ( Throwable $e ) {
        return $fallback;
    }
}
