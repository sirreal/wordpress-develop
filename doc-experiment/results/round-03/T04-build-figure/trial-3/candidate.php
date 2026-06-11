<?php
function build_figure( string $url, string $alt, string $caption ): string {
	// Start with a template that has the img attributes in the correct order (src, alt)
	// Using a template ensures the attribute order is preserved when we set values.
	$html = '<figure><img src="" alt=""><figcaption></figcaption></figure>';
	
	// Process the HTML with WP_HTML_Tag_Processor
	$processor = new WP_HTML_Tag_Processor( $html );
	
	// Find and set attributes on the img tag
	if ( $processor->next_tag( 'img' ) ) {
		$processor->set_attribute( 'src', $url );
		$processor->set_attribute( 'alt', $alt );
	}
	
	// Find and set the text content of figcaption
	// We need to find the #text token inside the figcaption
	while ( $processor->next_token() ) {
		if ( '#text' === $processor->get_token_name() ) {
			// Set the caption text using set_modifiable_text for proper HTML encoding
			$processor->set_modifiable_text( $caption );
			break;
		}
	}
	
	// Return the updated HTML
	return $processor->get_updated_html();
}
?>
