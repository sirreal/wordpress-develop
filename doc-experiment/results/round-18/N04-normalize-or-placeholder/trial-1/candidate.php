<?php
function normalize_or_placeholder( string $html ): string {
	$normalized = WP_HTML_Processor::normalize( $html );

	return null === $normalized
		? '<p>Unsupported HTML</p>'
		: $normalized;
}
