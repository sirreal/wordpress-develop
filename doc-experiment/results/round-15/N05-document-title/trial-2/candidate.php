<?php

function get_document_title( string $html ): ?string {
	$processor = WP_HTML_Tag_Processor::create_full_parser( $html );
	if ( null === $processor ) {
		return null;
	}

	if ( ! $processor->next_tag( array( 'tag_name' => 'TITLE' ) ) ) {
		return null;
	}

	$title = $processor->get_modifiable_text();
	return $title;
}
