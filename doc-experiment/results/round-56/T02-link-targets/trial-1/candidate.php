<?php

declare( strict_types=1 );

function add_link_targets( string $html ): string {
	$tags = new WP_HTML_Tag_Processor( $html );

	while ( $tags->next_tag( 'a' ) ) {
		if ( null === $tags->get_attribute( 'href' ) ) {
			continue;
		}

		$tags->set_attribute( 'target', '_blank' );
	}

	return $tags->get_updated_html();
}
