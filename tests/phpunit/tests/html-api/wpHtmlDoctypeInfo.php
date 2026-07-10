<?php
/**
 * Unit tests covering WP_HTML_Doctype_Info functionality.
 *
 * @package WordPress
 * @subpackage HTML-API
 */

/**
 * @group html-api
 *
 * @coversDefaultClass WP_HTML_Doctype_Info
 */
class Tests_HtmlApi_WpHtmlDoctypeInfo extends WP_UnitTestCase {
	/**
	 * Test DOCTYPE handling.
	 *
	 * @ticket 61576
	 *
	 * @dataProvider data_parseable_raw_doctypes
	 */
	public function test_doctype_doc_info(
		string $html,
		string $expected_compat_mode,
		?string $expected_name = null,
		?string $expected_public_id = null,
		?string $expected_system_id = null
	) {
		$doctype = WP_HTML_Doctype_Info::from_doctype_token( $html );
		$this->assertNotNull(
			$doctype,
			"Should have parsed the following doctype declaration: {$html}"
		);

		$this->assertSame(
			$expected_compat_mode,
			$doctype->indicated_compatibility_mode,
			'Failed to infer the expected document compatibility mode.'
		);

		$this->assertSame(
			$expected_name,
			$doctype->name,
			'Failed to parse the expected DOCTYPE name.'
		);

		$this->assertSame(
			$expected_public_id,
			$doctype->public_identifier,
			'Failed to parse the expected DOCTYPE public identifier.'
		);

		$this->assertSame(
			$expected_system_id,
			$doctype->system_identifier,
			'Failed to parse the expected DOCTYPE system identifier.'
		);
	}

	/**
	 * Data provider.
	 *
	 * @return array[]
	 */
	public static function data_parseable_raw_doctypes(): array {
		return array(
			'Missing doctype name'                         => array( '<!DOCTYPE>', 'quirks' ),
			'HTML5 doctype'                                => array( '<!DOCTYPE html>', 'no-quirks', 'html' ),
			'HTML5 doctype no whitespace before name'      => array( '<!DOCTYPEhtml>', 'no-quirks', 'html' ),
			'XHTML doctype'                                => array( '<!DOCTYPE HTML PUBLIC "-//W3C//DTD HTML 4.01//EN" "http://www.w3.org/TR/html4/strict.dtd">', 'no-quirks', 'html', '-//W3C//DTD HTML 4.01//EN', 'http://www.w3.org/TR/html4/strict.dtd' ),
			'SVG doctype'                                  => array( '<!DOCTYPE svg PUBLIC "-//W3C//DTD SVG 1.1//EN" "http://www.w3.org/Graphics/SVG/1.1/DTD/svg11.dtd">', 'quirks', 'svg', '-//W3C//DTD SVG 1.1//EN', 'http://www.w3.org/Graphics/SVG/1.1/DTD/svg11.dtd' ),
			'MathML doctype'                               => array( '<!DOCTYPE math PUBLIC "-//W3C//DTD MathML 2.0//EN" "http://www.w3.org/Math/DTD/mathml2/mathml2.dtd">', 'quirks', 'math', '-//W3C//DTD MathML 2.0//EN', 'http://www.w3.org/Math/DTD/mathml2/mathml2.dtd' ),
			'Doctype with null byte replacement'           => array( "<!DOCTYPE null-\0 PUBLIC '\0' '\0\0'>", 'quirks', "null-\u{FFFD}", "\u{FFFD}", "\u{FFFD}\u{FFFD}" ),
			'Uppercase doctype'                            => array( '<!DOCTYPE UPPERCASE>', 'quirks', 'uppercase' ),
			'Lowercase doctype'                            => array( '<!doctype lowercase>', 'quirks', 'lowercase' ),
			'Doctype with whitespace'                      => array( "<!DOCTYPE\n\thtml\f\rPUBLIC\r\n''\t''>", 'no-quirks', 'html', '', '' ),
			'Doctype trailing characters'                  => array( "<!DOCTYPE html PUBLIC '' '' Anything (except closing angle bracket) is just fine here !!!>", 'no-quirks', 'html', '', '' ),
			'An ugly no-quirks doctype'                    => array( "<!dOcTyPehtml\tPublIC\"pub-id\"'sysid'>", 'no-quirks', 'html', 'pub-id', 'sysid' ),
			'Missing public ID'                            => array( '<!DOCTYPE html PUBLIC>', 'quirks', 'html' ),
			'Missing system ID'                            => array( '<!DOCTYPE html SYSTEM>', 'quirks', 'html' ),
			'Missing close quote public ID'                => array( "<!DOCTYPE html PUBLIC 'xyz>", 'quirks', 'html', 'xyz' ),
			'Missing close quote system ID'                => array( "<!DOCTYPE html SYSTEM 'xyz>", 'quirks', 'html', null, 'xyz' ),
			'Missing close quote system ID with public'    => array( "<!DOCTYPE html PUBLIC 'abc' 'xyz>", 'quirks', 'html', 'abc', 'xyz' ),
			'Bogus characters instead of system/public'    => array( '<!DOCTYPE html FOOBAR>', 'quirks', 'html' ),
			'Bogus characters instead of PUBLIC quote'     => array( "<!DOCTYPE html PUBLIC x ''''>", 'quirks', 'html' ),
			'Bogus characters instead of SYSTEM quote '    => array( "<!DOCTYPE html SYSTEM x ''>", 'quirks', 'html' ),
			'Emoji'                                        => array( '<!DOCTYPE 🏴󠁧󠁢󠁥󠁮󠁧󠁿 PUBLIC "🔥" "😈">', 'quirks', "\u{1F3F4}\u{E0067}\u{E0062}\u{E0065}\u{E006E}\u{E0067}\u{E007F}", '🔥', '😈' ),
			'Bogus characters instead of SYSTEM quote after public' => array( "<!DOCTYPE html PUBLIC ''x''>", 'quirks', 'html', '' ),
			'Special quirks mode if system unset'          => array( '<!DOCTYPE html PUBLIC "-//W3C//DTD HTML 4.01 Frameset//">', 'quirks', 'html', '-//W3C//DTD HTML 4.01 Frameset//' ),
			'Special quirks mode if system empty'          => array( '<!DOCTYPE html PUBLIC "-//W3C//DTD HTML 4.01 Frameset//" "">', 'quirks', 'html', '-//W3C//DTD HTML 4.01 Frameset//', '' ),
			'Special limited-quirks mode if system is non-empty' => array( '<!DOCTYPE html PUBLIC "-//W3C//DTD HTML 4.01 Frameset//" "non-empty">', 'limited-quirks', 'html', '-//W3C//DTD HTML 4.01 Frameset//', 'non-empty' ),
			'Mixed-case system keyword'                    => array( "<!DOCTYPE html sYsTem 'about:legacy-compat'>", 'no-quirks', 'html', null, 'about:legacy-compat' ),

			/*
			 * Keyword and identifier matching must fold ASCII letters only, regardless
			 * of the process locale. The following bytes are case pairs of ASCII letters
			 * in common single-byte charmaps: 0xDD is İ in ISO-8859-9 (lowercase i) and
			 * 0xC4 is Ä in ISO-8859-1. A locale-sensitive comparison could fold them.
			 */
			'Non-ASCII byte does not match PUBLIC keyword' => array( "<!DOCTYPE html PUBL\xDDC 'xyz'>", 'quirks', 'html' ),
			'Non-ASCII byte does not match SYSTEM keyword' => array( "<!DOCTYPE html SYSTE\xCC 'xyz'>", 'quirks', 'html' ),
			'Non-ASCII byte in DOCTYPE name is preserved'  => array( "<!DOCTYPE HTML\xC4>", 'quirks', "html\xC4" ),
			'Quirky public ID matches ASCII-insensitively' => array( '<!DOCTYPE html PUBLIC "-//W3O//DTD W3 HTML STRICT 3.0//EN//">', 'quirks', 'html', '-//W3O//DTD W3 HTML STRICT 3.0//EN//' ),
			'Quirky public ID with non-ASCII byte does not match' => array( "<!DOCTYPE html PUBLIC \"-//W3O//DTD W3 HTML STR\xDDCT 3.0//EN//\">", 'no-quirks', 'html', "-//W3O//DTD W3 HTML STR\xDDCT 3.0//EN//" ),
		);
	}

	/**
	 * @dataProvider invalid_inputs
	 *
	 * @ticket 61576
	 */
	public function test_invalid_inputs_return_null( string $html ) {
		$this->assertNull( WP_HTML_Doctype_Info::from_doctype_token( $html ) );
	}

	/**
	 * Data provider.
	 *
	 * @return array[]
	 */
	public static function invalid_inputs(): array {
		return array(
			'Empty string'                      => array( '' ),
			'Other HTML'                        => array( '<div>' ),
			'DOCTYPE after HTML'                => array( 'x<!DOCTYPE>' ),
			'DOCTYPE before HTML'               => array( '<!DOCTYPE>x' ),
			'Incomplete DOCTYPE'                => array( '<!DOCTYPE' ),
			'Pseudo DOCTYPE containing ">"'     => array( '<!DOCTYPE html PUBLIC ">">' ),
			// 0xD6 is Ö in ISO-8859-1: it must not fold onto the ASCII letter O.
			'Non-ASCII byte in DOCTYPE keyword' => array( "<!D\xD6CTYPE html>" ),
		);
	}
}
