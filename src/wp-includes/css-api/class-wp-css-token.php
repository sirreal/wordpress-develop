<?php
/**
 * CSS API: WP_CSS_Token class
 *
 * @package WordPress
 * @subpackage CSS-API
 * @since 7.3.0
 */

/**
 * A token read by WP_CSS_Token_Processor.
 *
 * A token keeps its values after the processor moves to the next token, so
 * callers can collect tokens and read them later. The value is decoded from
 * the CSS source on the first call to get_value().
 *
 * Example:
 *
 * ```php interactive
 * $processor = new WP_CSS_Token_Processor( 'font-family: "Exo\20 2"' );
 * $tokens    = array();
 * while ( $processor->next_token() ) {
 *     $tokens[] = $processor->get_token();
 * }
 * echo $tokens[3]->get_type() . "\n";
 * echo $tokens[3]->get_value();
 * // Outputs:
 * // string-token
 * // Exo 2
 * ```
 *
 * @since 7.3.0
 *
 * @see WP_CSS_Token_Processor::get_token()
 */
final class WP_CSS_Token {
	/**
	 * Angle units, lowercase.
	 *
	 * @since 7.3.0
	 * @ignore
	 *
	 * @link https://drafts.csswg.org/css-values-4/#angles
	 *
	 * @var string[]
	 */
	private const ANGLE_UNITS = array( 'deg', 'grad', 'rad', 'turn' );

	/**
	 * Length units, lowercase.
	 *
	 * @since 7.3.0
	 * @ignore
	 *
	 * @link https://drafts.csswg.org/css-values-4/#lengths
	 * @link https://drafts.csswg.org/css-contain-3/#container-lengths
	 *
	 * @var string[]
	 */
	private const LENGTH_UNITS = array(
		'px',
		'cm',
		'mm',
		'q',
		'in',
		'pt',
		'pc',
		'em',
		'rem',
		'ex',
		'rex',
		'cap',
		'rcap',
		'ch',
		'rch',
		'ic',
		'ric',
		'lh',
		'rlh',
		'vw',
		'vh',
		'vi',
		'vb',
		'vmin',
		'vmax',
		'svw',
		'svh',
		'svi',
		'svb',
		'svmin',
		'svmax',
		'lvw',
		'lvh',
		'lvi',
		'lvb',
		'lvmin',
		'lvmax',
		'dvw',
		'dvh',
		'dvi',
		'dvb',
		'dvmin',
		'dvmax',
		'cqw',
		'cqh',
		'cqi',
		'cqb',
		'cqmin',
		'cqmax',
	);

	/**
	 * CSS source the offsets index.
	 *
	 * @since 7.3.0
	 * @ignore
	 *
	 * @var string
	 */
	private $css;

	/**
	 * Token type.
	 *
	 * @since 7.3.0
	 * @ignore
	 *
	 * @var string
	 * @phpstan-var WP_CSS_Token_Processor::TOKEN_*
	 */
	private $type;

	/**
	 * Byte offset at which the token starts.
	 *
	 * @since 7.3.0
	 * @ignore
	 *
	 * @var int
	 */
	private $start;

	/**
	 * Byte length of the token.
	 *
	 * @since 7.3.0
	 * @ignore
	 *
	 * @var int
	 */
	private $length;

	/**
	 * Type flag of a hash, number or dimension token.
	 *
	 * @since 7.3.0
	 * @ignore
	 *
	 * @var string|null
	 * @phpstan-var 'id'|'unrestricted'|'integer'|'number'|null
	 */
	private $flag;

	/**
	 * Decoded unit of a dimension token.
	 *
	 * @since 7.3.0
	 * @ignore
	 *
	 * @var string|null
	 */
	private $unit;

	/**
	 * Byte offset at which the value of a string, url or comment token starts.
	 *
	 * @since 7.3.0
	 * @ignore
	 *
	 * @var int|null
	 */
	private $value_start;

	/**
	 * Byte length of the value of a string, url or comment token.
	 *
	 * @since 7.3.0
	 * @ignore
	 *
	 * @var int|null
	 */
	private $value_length;

	/**
	 * Whether the token ended at its closing delimiter.
	 *
	 * @since 7.3.0
	 * @ignore
	 *
	 * @var bool
	 */
	private $is_terminated;

	/**
	 * Decoded value, or false before the first call to get_value().
	 *
	 * @since 7.3.0
	 * @ignore
	 *
	 * @var string|null|false
	 */
	private $value = false;

	/**
	 * Constructor.
	 *
	 * Use WP_CSS_Token_Processor::get_token() to create tokens.
	 *
	 * @since 7.3.0
	 * @access private
	 * @ignore
	 *
	 * @param string      $css           CSS source the offsets index.
	 * @param string      $type          Token type, one of the WP_CSS_Token_Processor::TOKEN_* constants.
	 * @param int         $start         Byte offset at which the token starts.
	 * @param int         $length        Byte length of the token.
	 * @param string|null $flag          Type flag of a hash, number or dimension token.
	 * @param string|null $unit          Decoded unit of a dimension token.
	 * @param int|null    $value_start   Byte offset at which the value of a string, url or comment token starts.
	 * @param int|null    $value_length  Byte length of the value of a string, url or comment token.
	 * @param bool        $is_terminated Whether the token ended at its closing delimiter.
	 */
	public function __construct(
		string $css,
		string $type,
		int $start,
		int $length,
		?string $flag,
		?string $unit,
		?int $value_start,
		?int $value_length,
		bool $is_terminated
	) {
		$this->css           = $css;
		$this->type          = $type;
		$this->start         = $start;
		$this->length        = $length;
		$this->flag          = $flag;
		$this->unit          = $unit;
		$this->value_start   = $value_start;
		$this->value_length  = $value_length;
		$this->is_terminated = $is_terminated;
	}

	/**
	 * Gets the token type.
	 *
	 * @since 7.3.0
	 *
	 * @return string One of the WP_CSS_Token_Processor::TOKEN_* constants.
	 * @phpstan-return WP_CSS_Token_Processor::TOKEN_*
	 */
	public function get_type(): string {
		return $this->type;
	}

	/**
	 * Gets the type flag.
	 *
	 * Hash tokens have an "id" or "unrestricted" flag. Number and dimension
	 * tokens have an "integer" or "number" flag. Other tokens have none.
	 *
	 * @since 7.3.0
	 *
	 * @return string|null The type flag, or null.
	 * @phpstan-return 'id'|'unrestricted'|'integer'|'number'|null
	 */
	public function get_flag(): ?string {
		return $this->flag;
	}

	/**
	 * Gets the decoded value.
	 *
	 * The value is the token's text after input preprocessing and escape
	 * decoding, without its delimiters:
	 *
	 * - ident, delim: the whole token.
	 * - function: the name, without `(`.
	 * - at-keyword, hash: the name, without `@` or `#`.
	 * - string, url: the contents, without quotes or `url(` and `)`.
	 * - comment: the text between `/*` and `*` `/`. Comments contain no
	 *   escapes, so only preprocessing applies.
	 * - number, percentage, dimension: the number as written in the source,
	 *   without `%` or the unit. CSS Syntax 3 parses it as a number; this
	 *   method keeps the text.
	 * - Every other type, including bad-string and bad-url: null.
	 *
	 * The value is decoded on the first call and kept for later calls.
	 *
	 * Example:
	 *
	 * ```php interactive
	 * $processor = new WP_CSS_Token_Processor( '"a\\62 c"' );
	 * $processor->next_token();
	 * echo $processor->get_token()->get_value();
	 * // Outputs: abc
	 * ```
	 *
	 * @since 7.3.0
	 *
	 * @link https://www.w3.org/TR/css-syntax-3/#tokenization
	 *
	 * @return string|null The decoded value, or null for token types without a value.
	 */
	public function get_value(): ?string {
		if ( false === $this->value ) {
			$this->value = $this->decode_value();
		}

		return $this->value;
	}

	/**
	 * Gets the decoded unit of a dimension token.
	 *
	 * @since 7.3.0
	 *
	 * @return string|null The unit, or null for other token types.
	 */
	public function get_unit(): ?string {
		return $this->unit;
	}

	/**
	 * Gets the byte offset at which the token starts in the CSS source.
	 *
	 * @since 7.3.0
	 *
	 * @return int Byte offset.
	 */
	public function get_start(): int {
		return $this->start;
	}

	/**
	 * Gets the byte length of the token in the CSS source.
	 *
	 * @since 7.3.0
	 *
	 * @return int Byte length.
	 */
	public function get_length(): int {
		return $this->length;
	}

	/**
	 * Reports whether the token ended at its closing delimiter.
	 *
	 * Comment, string, url and bad-url tokens have a closing delimiter: `*` `/`,
	 * the matching quote, or `)`. When the input ends before the delimiter,
	 * the tokenizer still returns the token, and this method returns false.
	 * It returns true for every other token type, which have no closing
	 * delimiter.
	 *
	 * Example:
	 *
	 * ```php interactive
	 * $processor = new WP_CSS_Token_Processor( '"abc' );
	 * $processor->next_token();
	 * $token = $processor->get_token();
	 * echo $token->get_type() . "\n";
	 * var_dump( $token->is_terminated() );
	 * // Outputs:
	 * // string-token
	 * // bool(false)
	 * ```
	 *
	 * @since 7.3.0
	 *
	 * @link https://www.w3.org/TR/css-syntax-3/#consume-comment
	 * @link https://www.w3.org/TR/css-syntax-3/#consume-string-token
	 * @link https://www.w3.org/TR/css-syntax-3/#consume-url-token
	 *
	 * @return bool Whether the token ended at its closing delimiter.
	 */
	public function is_terminated(): bool {
		return $this->is_terminated;
	}

	/**
	 * Reports whether the token is a CSS angle.
	 *
	 * An angle is a dimension token whose unit is an ASCII case-insensitive
	 * match for `deg`, `grad`, `rad` or `turn`. The unit is compared after
	 * escape decoding.
	 *
	 * A number token is never an angle, not even `0`. CSS allows a bare `0`
	 * for an angle only in some legacy grammars, and not in properties such
	 * as `rotate`.
	 *
	 * Example:
	 *
	 * ```php interactive
	 * $processor = new WP_CSS_Token_Processor( '-.5TURN 1\\64 eg 0 45' );
	 * while ( $processor->next_token() ) {
	 *     $token = $processor->get_token();
	 *     if ( WP_CSS_Token_Processor::TOKEN_WHITESPACE !== $token->get_type() ) {
	 *         var_dump( $token->is_angle() );
	 *     }
	 * }
	 * // Outputs:
	 * // bool(true)
	 * // bool(true)
	 * // bool(false)
	 * // bool(false)
	 * ```
	 *
	 * @since 7.3.0
	 *
	 * @link https://drafts.csswg.org/css-values-4/#angles
	 *
	 * @return bool Whether the token is an angle.
	 */
	public function is_angle(): bool {
		return (
			WP_CSS_Token_Processor::TOKEN_DIMENSION === $this->type &&
			in_array( strtolower( (string) $this->unit ), self::ANGLE_UNITS, true )
		);
	}

	/**
	 * Reports whether the token is a CSS length.
	 *
	 * A length is a dimension token whose unit is an ASCII case-insensitive
	 * match for a length unit, or a number token whose value is zero. The
	 * unit is compared after escape decoding. The length units are the
	 * absolute, font-relative and viewport units of CSS Values 4 and the
	 * container query units of CSS Containment 3.
	 *
	 * CSS allows a bare zero for a length. A number token is zero when every
	 * digit before its exponent is `0`, whatever its sign, decimal point or
	 * exponent: `0`, `-0`, `0.0`, `.0` and `0e3`. A nonzero number is not a
	 * length, even one too small to represent as a float, such as `1e-400`.
	 *
	 * Percentages are not lengths. Check for a percentage token separately
	 * where CSS accepts `<length-percentage>`.
	 *
	 * Example:
	 *
	 * ```php interactive
	 * $processor = new WP_CSS_Token_Processor( '10DVH 1\\70 x -0.0 50% 10' );
	 * while ( $processor->next_token() ) {
	 *     $token = $processor->get_token();
	 *     if ( WP_CSS_Token_Processor::TOKEN_WHITESPACE !== $token->get_type() ) {
	 *         var_dump( $token->is_length() );
	 *     }
	 * }
	 * // Outputs:
	 * // bool(true)
	 * // bool(true)
	 * // bool(true)
	 * // bool(false)
	 * // bool(false)
	 * ```
	 *
	 * @since 7.3.0
	 *
	 * @link https://drafts.csswg.org/css-values-4/#lengths
	 * @link https://drafts.csswg.org/css-contain-3/#container-lengths
	 *
	 * @return bool Whether the token is a length.
	 */
	public function is_length(): bool {
		switch ( $this->type ) {
			case WP_CSS_Token_Processor::TOKEN_DIMENSION:
				return in_array( strtolower( (string) $this->unit ), self::LENGTH_UNITS, true );

			case WP_CSS_Token_Processor::TOKEN_NUMBER:
				// A number is zero when its digits before any exponent are all `0`.
				$number = (string) $this->get_value();
				return '' === trim( substr( $number, 0, strcspn( $number, 'eE' ) ), '+-.0' );

			default:
				return false;
		}
	}

	/**
	 * Decodes the value from the CSS source.
	 *
	 * @since 7.3.0
	 * @ignore
	 *
	 * @return string|null The decoded value, or null for token types without a value.
	 */
	private function decode_value(): ?string {
		switch ( $this->type ) {
			case WP_CSS_Token_Processor::TOKEN_HASH:
			case WP_CSS_Token_Processor::TOKEN_AT_KEYWORD:
				// The value starts after the `#` or `@`.
				return WP_CSS_Token_Processor::decode_range( $this->css, $this->start + 1, $this->length - 1 );

			case WP_CSS_Token_Processor::TOKEN_FUNCTION:
				// The value ends before the `(`.
				return WP_CSS_Token_Processor::decode_range( $this->css, $this->start, $this->length - 1 );

			case WP_CSS_Token_Processor::TOKEN_IDENT:
			case WP_CSS_Token_Processor::TOKEN_DELIM:
				return WP_CSS_Token_Processor::decode_range( $this->css, $this->start, $this->length );

			case WP_CSS_Token_Processor::TOKEN_STRING:
				return WP_CSS_Token_Processor::decode_range( $this->css, $this->value_start, $this->value_length, true );

			case WP_CSS_Token_Processor::TOKEN_URL:
				return WP_CSS_Token_Processor::decode_range( $this->css, $this->value_start, $this->value_length );

			case WP_CSS_Token_Processor::TOKEN_COMMENT:
				return WP_CSS_Token_Processor::preprocess_range( $this->css, $this->value_start, $this->value_length );

			case WP_CSS_Token_Processor::TOKEN_NUMBER:
				// Numbers are ASCII and have no escapes.
				return substr( $this->css, $this->start, $this->length );

			case WP_CSS_Token_Processor::TOKEN_PERCENTAGE:
				// The value ends before the `%`.
				return substr( $this->css, $this->start, $this->length - 1 );

			case WP_CSS_Token_Processor::TOKEN_DIMENSION:
				// The value ends before the unit.
				return substr( WP_CSS_Token_Processor::decode_range( $this->css, $this->start, $this->length ), 0, -strlen( $this->unit ) );

			default:
				return null;
		}
	}
}
