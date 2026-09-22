<?php
function get_document_title( string $html ): ?string {
	$processor = WP_HTML_Tag_Processor::create_fragment( $html, '<html>' );
	if ( null === $processor ) {
		return null;
	}

	// Find the title tag
	if ( ! $processor->next_tag( array( 'tag_name' => 'title' ) ) ) {
		return null;
	}

	// Move to the text content inside the title tag
	if ( ! $processor->next_token() ) {
		return null;
	}

	// Get the text content - it's already decoded by the processor
	$text = $processor->get_modifiable_text();

	return $text;
}
