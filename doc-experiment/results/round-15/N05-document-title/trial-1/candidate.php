<?php

function get_document_title( string $html ): ?string {
	$processor = new WP_HTML_Tag_Processor( $html );
	
	if ( $processor->next_tag( array( 'tag_name' => 'title' ) ) ) {
		// TITLE is a special element where content is plaintext with decoded character references.
		// get_modifiable_text() returns the decoded content directly.
		return $processor->get_modifiable_text();
	}
	
	return null;
}
