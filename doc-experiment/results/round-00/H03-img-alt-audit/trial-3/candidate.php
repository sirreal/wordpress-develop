<?php

function find_images_missing_alt( string $html ): array {
	$missing = array();
	$processor = new WP_HTML_Tag_Processor( $html );

	while ( $processor->next_tag( 'img' ) ) {
		$src = $processor->get_attribute( 'src' );

		// Skip IMG tags that have no src attribute.
		if ( null === $src ) {
			continue;
		}

		$alt = $processor->get_attribute( 'alt' );

		// "Missing or empty" means:
		// - absent: null
		// - present without value (boolean): true
		// - empty string: ''
		// alt containing only whitespace is considered present and does NOT count.
		if ( null === $alt || true === $alt || '' === $alt ) {
			// src is the decoded attribute value; cast to string in case it's somehow true.
			$missing[] = (string) $src;
		}
	}

	return $missing;
}
