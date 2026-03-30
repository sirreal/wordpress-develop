<?php

abstract class WP_CSS_Builder {
	/**
	 * Create a quoted CSS string from a plain PHP string value.
	 *
	 * Example:
	 *     $value = 'CSS & a "<style>" tag\'s strings';
	 *     $css_string = WP_CSS_Builder::string( $value );
	 *     echo "<style>*::before { content: {$css_string}; }</style>";
	 *
	 * CSS strings are quoted many characters that are problematic in HTML
	 * or may be complicated for rudimentary CSS or HTML processors to handle
	 * are encoded using Unicode escape sequences.
	 *
	 * @see https://www.w3.org/TR/css-syntax-3/#escaping
	 */
	public static function string( string $value ): string {
		$escaped = strtr(
			$value,
			array(
				// Escape existing backslashes to prevent unintentional escapes in result.
				'\\'   => '\\5C ',

				// Pre-processing replaces NULLs and some newlines. Replace and escape as necessary.
				"\0"   => "\u{FFFD}",

				// Normalize and replace newlines. https://www.w3.org/TR/css-syntax-3/#input-preprocessing
				"\r\n" => '\\A ',
				"\r"   => '\\A ',
				"\f"   => '\\A ',

				// Newlines must be escaped in CSS strings.
				"\n"   => '\\A ',

				// Arbitrary characters for Unicode escaping:

				// HTML syntax may be problematic.
				'<'    => '\\3C ',
				'>'    => '\\3E ',
				'&'    => '\\26 ',

				// CSS syntax may be problematic.
				','    => '\\2C ',
				';'    => '\\3B ',
				'{'    => '\\7B ',
				'}'    => '\\7D ',
				'"'    => '\\22 ',
				"'"    => '\\27 ',
			)
		);
		return "\"{$escaped}\"";
	}
}
