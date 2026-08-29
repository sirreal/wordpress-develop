<?php
/**
 * Builds and returns an HTML figure fragment.
 *
 * @param string $url     The URL for the img src attribute.
 * @param string $alt     The alt text for the img element.
 * @param string $caption The text content for the figcaption element.
 *
 * @return string The HTML figure fragment with img and figcaption elements.
 */
function build_figure( string $url, string $alt, string $caption ): string {
	// Start with a template containing the basic structure and placeholder text.
	// The text content will be overwritten via the API.
	$html = '<figure><img><figcaption>x</figcaption></figure>';

	// Use WP_HTML_Tag_Processor to set attributes with proper encoding.
	$processor = new WP_HTML_Tag_Processor( $html );

	// Find and configure the img tag.
	if ( $processor->next_tag( 'img' ) ) {
		// Set attributes in the required order: src, then alt.
		// The processor handles proper escaping of special characters.
		$processor->set_attribute( 'src', $url );
		$processor->set_attribute( 'alt', $alt );
	}

	// Get the HTML with img attributes set.
	$html = $processor->get_updated_html();

	// Create a new processor to find and modify the figcaption text.
	$processor = new WP_HTML_Tag_Processor( $html );

	// Find the figcaption tag.
	if ( $processor->next_tag( 'figcaption' ) ) {
		// Advance to the text node inside figcaption.
		if ( $processor->next_token() ) {
			// Replace the placeholder text with the actual caption.
			$processor->set_modifiable_text( $caption );
		}
	}

	return $processor->get_updated_html();
}
