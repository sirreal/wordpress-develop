<?php

function can_normalize_fragment( string $html ): bool {
	$normalized = WP_HTML_Processor::normalize( $html );
	return null !== $normalized;
}
