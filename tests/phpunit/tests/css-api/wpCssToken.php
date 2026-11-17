<?php

/**
 * Tests for WP_CSS_Token and WP_CSS_Token_Processor::get_token().
 *
 * @group css-api
 *
 * @coversDefaultClass WP_CSS_Token
 */
class Tests_CssApi_WpCssToken extends WP_UnitTestCase {
	/**
	 * Tests that tokens read with get_token() match the corpus.
	 *
	 * @ticket 65738
	 * @dataProvider data_corpus
	 *
	 * @covers WP_CSS_Token_Processor::get_token
	 */
	public function test_get_token_matches_spec( string $css, array $expected_tokens ): void {
		$processor = new WP_CSS_Token_Processor( $css );
		$tokens    = array();
		while ( $processor->next_token() ) {
			$tokens[] = $processor->get_token();
		}

		$actual_tokens = array();
		foreach ( $tokens as $token ) {
			$actual_token = array(
				'type'       => $token->get_type(),
				'startIndex' => $token->get_start(),
				'endIndex'   => $token->get_start() + $token->get_length(),
				'value'      => $token->get_value(),
			);
			if ( null !== $token->get_unit() ) {
				$actual_token['unit'] = $token->get_unit();
			}
			if (
				WP_CSS_Token_Processor::TOKEN_NUMBER === $token->get_type() ||
				WP_CSS_Token_Processor::TOKEN_DIMENSION === $token->get_type()
			) {
				$actual_token['numberType'] = $token->get_flag();
			}
			$actual_tokens[] = $actual_token;
		}

		$expected_tokens = array_map(
			static function ( array $token ): array {
				unset( $token['raw'], $token['normalized'] );
				return $token;
			},
			$expected_tokens
		);

		$this->assertSame( $expected_tokens, $actual_tokens );
	}

	/**
	 * Data provider for test_get_token_matches_spec().
	 *
	 * @return array[]
	 */
	public static function data_corpus(): array {
		return json_decode( file_get_contents( DIR_TESTDATA . '/css-api/css-test-cases.json' ), true );
	}

	/**
	 * Tests that get_token() returns null when the processor has no current token.
	 *
	 * @ticket 65738
	 *
	 * @covers WP_CSS_Token_Processor::get_token
	 */
	public function test_get_token_returns_null_without_a_current_token(): void {
		$processor = new WP_CSS_Token_Processor( 'a' );
		$this->assertNull( $processor->get_token(), 'There is no token before next_token().' );

		$this->assertTrue( $processor->next_token() );
		$this->assertInstanceOf( WP_CSS_Token::class, $processor->get_token() );

		$this->assertFalse( $processor->next_token() );
		$this->assertNull( $processor->get_token(), 'There is no token after the last one.' );
	}

	/**
	 * Tests that a token decodes its value after the processor has moved on.
	 *
	 * @ticket 65738
	 *
	 * @covers ::get_value
	 */
	public function test_value_decodes_after_the_processor_moves_on(): void {
		$processor = new WP_CSS_Token_Processor( '\\66 oo "b\\61 r" url(b\\61 z) #\\31 a 1\\70 x' );
		$tokens    = array();
		while ( $processor->next_token() ) {
			$tokens[] = $processor->get_token();
		}

		$values = array();
		foreach ( $tokens as $token ) {
			$values[] = array( $token->get_type(), $token->get_value() );
		}

		$this->assertSame(
			array(
				array( WP_CSS_Token_Processor::TOKEN_IDENT, 'foo' ),
				array( WP_CSS_Token_Processor::TOKEN_WHITESPACE, null ),
				array( WP_CSS_Token_Processor::TOKEN_STRING, 'bar' ),
				array( WP_CSS_Token_Processor::TOKEN_WHITESPACE, null ),
				array( WP_CSS_Token_Processor::TOKEN_URL, 'baz' ),
				array( WP_CSS_Token_Processor::TOKEN_WHITESPACE, null ),
				array( WP_CSS_Token_Processor::TOKEN_HASH, '1a' ),
				array( WP_CSS_Token_Processor::TOKEN_WHITESPACE, null ),
				array( WP_CSS_Token_Processor::TOKEN_DIMENSION, '1' ),
			),
			$values
		);
		$this->assertSame( 'px', $tokens[8]->get_unit() );
	}

	/**
	 * Tests that a token reports the type flag of the token it was read from.
	 *
	 * @ticket 65738
	 * @dataProvider data_flag
	 *
	 * @covers ::get_flag
	 */
	public function test_flag( string $css, string $expected_type, ?string $expected_flag ): void {
		$processor = new WP_CSS_Token_Processor( $css );
		$this->assertTrue( $processor->next_token() );

		$token = $processor->get_token();
		$this->assertSame( $expected_type, $token->get_type() );
		$this->assertSame( $expected_flag, $token->get_flag() );
	}

	/**
	 * Data provider for test_flag().
	 *
	 * @return array[]
	 */
	public static function data_flag(): array {
		return array(
			'hash id'            => array( '#a1', WP_CSS_Token_Processor::TOKEN_HASH, WP_CSS_Token_Processor::HASH_TOKEN_ID ),
			'hash escaped digit' => array( '#\\31 a', WP_CSS_Token_Processor::TOKEN_HASH, WP_CSS_Token_Processor::HASH_TOKEN_ID ),
			'hash unrestricted'  => array( '#1a', WP_CSS_Token_Processor::TOKEN_HASH, WP_CSS_Token_Processor::HASH_TOKEN_UNRESTRICTED ),
			'integer'            => array( '12', WP_CSS_Token_Processor::TOKEN_NUMBER, 'integer' ),
			'number'             => array( '1.5', WP_CSS_Token_Processor::TOKEN_NUMBER, 'number' ),
			'integer dimension'  => array( '1px', WP_CSS_Token_Processor::TOKEN_DIMENSION, 'integer' ),
			'number dimension'   => array( '1e2px', WP_CSS_Token_Processor::TOKEN_DIMENSION, 'number' ),
			'percentage'         => array( '50%', WP_CSS_Token_Processor::TOKEN_PERCENTAGE, null ),
			'ident'              => array( 'a', WP_CSS_Token_Processor::TOKEN_IDENT, null ),
		);
	}

	/**
	 * Tests that a token reports whether it ended at its closing delimiter.
	 *
	 * @ticket 65738
	 * @dataProvider data_is_terminated
	 *
	 * @covers ::is_terminated
	 */
	public function test_is_terminated( string $css, bool $expected ): void {
		$processor = new WP_CSS_Token_Processor( $css );
		$this->assertTrue( $processor->next_token() );

		$token = $processor->get_token();
		$processor->next_token();
		$this->assertSame( $expected, $token->is_terminated(), 'The flag must not change when the processor moves on.' );
	}

	/**
	 * Data provider for test_is_terminated().
	 *
	 * @return array[]
	 */
	public static function data_is_terminated(): array {
		return array(
			'string terminated'    => array( '"a" b', true ),
			'string unterminated'  => array( '"a', false ),
			'url terminated'       => array( 'url(a) b', true ),
			'url unterminated'     => array( 'url(a', false ),
			'comment terminated'   => array( '/* a */ b', true ),
			'comment unterminated' => array( '/* a', false ),
			'ident'                => array( 'a b', true ),
		);
	}

	/**
	 * Tests that the value of a comment token is the text between its delimiters.
	 *
	 * @ticket 65738
	 * @dataProvider data_comment_value
	 *
	 * @covers ::get_value
	 */
	public function test_comment_value( string $css, string $expected ): void {
		$processor = new WP_CSS_Token_Processor( $css );
		$this->assertTrue( $processor->next_token() );
		$this->assertSame( WP_CSS_Token_Processor::TOKEN_COMMENT, $processor->get_token_type() );

		$this->assertSame( $expected, $processor->get_token()->get_value() );
	}

	/**
	 * Data provider for test_comment_value().
	 *
	 * @return array[]
	 */
	public static function data_comment_value(): array {
		return array(
			'terminated'                        => array( '/* a */', ' a ' ),
			'unterminated'                      => array( '/* a', ' a' ),
			'opener star does not close'        => array( '/*/', '/' ),
			'no escapes, newlines preprocessed' => array( "/*\\41\r\n*/", "\\41\n" ),
		);
	}

	/**
	 * Tests that a token reports whether it is an angle.
	 *
	 * @ticket 65738
	 * @dataProvider data_is_angle
	 *
	 * @covers ::is_angle
	 */
	public function test_is_angle( string $css, bool $expected ): void {
		$this->assertSame( $expected, self::get_only_token( $css )->is_angle() );
	}

	/**
	 * Data provider for test_is_angle().
	 *
	 * @return array[]
	 */
	public static function data_is_angle(): array {
		return array(
			'degrees'                      => array( '45deg', true ),
			'gradians'                     => array( '100grad', true ),
			'radians'                      => array( '1.5rad', true ),
			'turns'                        => array( '.5turn', true ),
			'negative'                     => array( '-15deg', true ),
			'leading plus sign'            => array( '+1deg', true ),
			'zero with a unit'             => array( '0deg', true ),
			'exponent'                     => array( '1e2deg', true ),
			'negative exponent'            => array( '1e-1turn', true ),
			'uppercase unit'               => array( '1DEG', true ),
			'mixed case unit'              => array( '100GrAd', true ),
			'hex escape in the unit'       => array( '1\\64 eg', true ),
			'identity escape in the unit'  => array( '1de\\g', true ),
			'number 0'                     => array( '0', false ),
			'number other than 0'          => array( '45', false ),
			'percentage'                   => array( '50%', false ),
			'length'                       => array( '1px', false ),
			'unit with a suffix'           => array( '1degs', false ),
			'unit with a trailing null'    => array( "1deg\0", false ),
			'non-ASCII letter in the unit' => array( "1d\u{0435}g", false ),
			'ident'                        => array( 'deg', false ),
			'function'                     => array( 'calc(', false ),
		);
	}

	/**
	 * Returns the token of CSS that tokenizes as exactly one token.
	 *
	 * @param string $css CSS of one token.
	 * @return WP_CSS_Token The token.
	 */
	private static function get_only_token( string $css ): WP_CSS_Token {
		$processor = new WP_CSS_Token_Processor( $css );
		self::assertTrue( $processor->next_token(), 'The CSS must have a token.' );
		$token = $processor->get_token();
		self::assertFalse( $processor->next_token(), 'The CSS must have only one token.' );

		return $token;
	}
}
