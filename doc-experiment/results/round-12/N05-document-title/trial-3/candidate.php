<?php

/**
 * Extract the document title from a complete HTML document.
 *
 * Given a complete HTML document, returns the text of its <title> element
 * with character references decoded, or null if the document has no <title>
 * element. An existing but empty <title></title> returns the empty string.
 *
 * @param string $html The complete HTML document.
 * @return string|null The title text with character references decoded, or null if no title exists.
 */
function get_document_title( string $html ): ?string {
	$processor = WP_HTML_Processor::create_full_parser( $html );
	
	if ( null === $processor ) {
		return null;
	}
	
	// Search for the TITLE tag.
	if ( $processor->next_tag( array( 'tag_name' => 'title' ) ) ) {
		// The TITLE element's text content is available via get_modifiable_text(),
		// which automatically decodes character references and handles the special
		// case that TITLE contents are plaintext.
		return $processor->get_modifiable_text();
	}
	
	return null;
}
