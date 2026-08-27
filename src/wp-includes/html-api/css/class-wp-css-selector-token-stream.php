<?php
/**
 * HTML API: WP_CSS_Selector_Token_Stream class
 *
 * @package WordPress
 * @subpackage HTML-API
 * @since {WP_VERSION}
 */

/**
 * Token stream used by CSS selector parsers.
 *
 * This class is a thin grammar-facing wrapper around {@see WP_CSS_Token_Processor}.
 * Selector classes consume these tokens instead of inspecting selector bytes.
 *
 * @since {WP_VERSION}
 *
 * @access private
 */
final class WP_CSS_Selector_Token_Stream {
	/**
	 * Selector input after UTF-8 byte-stream decoding.
	 *
	 * @var string
	 */
	private $input;

	/**
	 * Token metadata from WP_CSS_Token_Processor.
	 *
	 * @var array<int, array{type:string, value:string|null, type_flag:string|null, start:int, length:int}>
	 */
	private $tokens;

	/**
	 * Current token index.
	 *
	 * @var int
	 */
	private $index = 0;

	/**
	 * Constructor.
	 *
	 * @param string $input Selector input after UTF-8 decoding.
	 * @param array  $tokens Token metadata.
	 */
	private function __construct( string $input, array $tokens ) {
		$this->input  = $input;
		$this->tokens = $tokens;
	}

	/**
	 * Creates a selector token stream.
	 *
	 * Selector strings are UTF-8 text. Ill-formed byte sequences are replaced
	 * with U+FFFD before tokenization, matching CSS Syntax's byte-stream decode
	 * step, and reported because they are usually developer mistakes.
	 *
	 * @param string $input CSS selector input.
	 * @param string $called_class Parser class name used for incorrect-usage notices.
	 * @return self
	 */
	public static function from_selectors( string $input, string $called_class ): self {
		$scrubbed = wp_scrub_utf8( $input );
		if ( $scrubbed !== $input ) {
			_doing_it_wrong(
				$called_class . '::from_selectors',
				'Selector strings must be valid UTF-8: ill-formed byte sequences were replaced with U+FFFD (�), which is unlikely to match the intended elements.',
				'{WP_VERSION}'
			);
			$input = $scrubbed;
		}

		$processor = WP_CSS_Token_Processor::create( $input );
		$tokens    = array();

		while ( $processor->next_token() ) {
			/*
			 * CSS comments are consumed during tokenization and do not become
			 * parser tokens. The token processor exposes them for stylesheet
			 * rewriting, but selector grammar should never see them.
			 */
			if ( WP_CSS_Token_Processor::TOKEN_COMMENT === $processor->get_token_type() ) {
				continue;
			}

			$tokens[] = array(
				'type'      => $processor->get_token_type(),
				'value'     => $processor->get_token_value(),
				'type_flag' => $processor->get_token_type_flag(),
				'start'     => $processor->get_token_start(),
				'length'    => $processor->get_token_length(),
			);
		}

		return new self( $input, $tokens );
	}

	/**
	 * Returns whether the stream is positioned at EOF.
	 *
	 * @return bool
	 */
	public function is_eof(): bool {
		return $this->index >= count( $this->tokens );
	}

	/**
	 * Returns a bookmark for the current stream position.
	 *
	 * @return int
	 */
	public function bookmark(): int {
		return $this->index;
	}

	/**
	 * Restores the stream to a previous bookmark.
	 *
	 * @param int $bookmark Bookmark returned by {@see WP_CSS_Selector_Token_Stream::bookmark()}.
	 */
	public function seek( int $bookmark ): void {
		$this->index = $bookmark;
	}

	/**
	 * Returns the current token type.
	 *
	 * @return string|null
	 */
	public function get_token_type(): ?string {
		return $this->tokens[ $this->index ]['type'] ?? null;
	}

	/**
	 * Returns the current token value.
	 *
	 * @return string|null
	 */
	public function get_token_value(): ?string {
		return $this->tokens[ $this->index ]['value'] ?? null;
	}

	/**
	 * Returns the current token type flag.
	 *
	 * @return string|null
	 */
	public function get_token_type_flag(): ?string {
		return $this->tokens[ $this->index ]['type_flag'] ?? null;
	}

	/**
	 * Checks whether the current token matches a type and optional value.
	 *
	 * @param string      $type Token type.
	 * @param string|null $value Optional token value.
	 * @return bool
	 */
	public function matches( string $type, ?string $value = null ): bool {
		if ( $type !== $this->get_token_type() ) {
			return false;
		}

		return null === $value || $value === $this->get_token_value();
	}

	/**
	 * Consumes the current token when it matches a type and optional value.
	 *
	 * @param string      $type Token type.
	 * @param string|null $value Optional token value.
	 * @return bool Whether a matching token was consumed.
	 */
	public function consume( string $type, ?string $value = null ): bool {
		if ( ! $this->matches( $type, $value ) ) {
			return false;
		}

		++$this->index;
		return true;
	}

	/**
	 * Consumes a delimiter token with the given value.
	 *
	 * @param string $value Delimiter value.
	 * @return bool Whether a matching delimiter was consumed.
	 */
	public function consume_delim( string $value ): bool {
		return $this->consume( WP_CSS_Token_Processor::TOKEN_DELIM, $value );
	}

	/**
	 * Consumes an ident token and returns its value.
	 *
	 * @return string|null Ident token value, or null if the current token is not an ident.
	 */
	public function consume_ident(): ?string {
		if ( ! $this->matches( WP_CSS_Token_Processor::TOKEN_IDENT ) ) {
			return null;
		}

		$value = $this->get_token_value();
		++$this->index;
		return $value;
	}

	/**
	 * Consumes whitespace tokens.
	 *
	 * @return bool Whether any whitespace was consumed.
	 */
	public function consume_whitespace(): bool {
		$advanced = false;

		while ( $this->consume( WP_CSS_Token_Processor::TOKEN_WHITESPACE ) ) {
			$advanced = true;
		}

		return $advanced;
	}

	/**
	 * Returns the remaining original selector text from the current token.
	 *
	 * This is intended for parser tests and diagnostics, not selector grammar.
	 *
	 * @return string
	 */
	public function get_remaining_text(): string {
		if ( $this->is_eof() ) {
			return '';
		}

		return substr( $this->input, $this->tokens[ $this->index ]['start'] );
	}
}
