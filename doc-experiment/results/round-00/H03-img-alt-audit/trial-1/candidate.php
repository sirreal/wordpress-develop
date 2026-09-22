<?php

function find_images_missing_alt( string $html ): array {
	$missing = array();

	$processor = new WP_HTML_Tag_Processor( $html );

	while ( $processor->next_tag( 'img' ) ) {
		$src = $processor->get_attribute( 'src' );

		// Skip IMG tags with no src attribute.
		if ( null === $src || true === $src ) {
			continue;
		}

		$alt = $processor->get_attribute( 'alt' );

		// "Missing or empty": absent (null), boolean/valueless (true), or empty string ("").
		// An alt containing only whitespace is considered present and does not count.
		if ( null === $alt || true === $alt || '' === $alt ) {
			$missing[] = $src;
		}
	}

	return $missing;
}
