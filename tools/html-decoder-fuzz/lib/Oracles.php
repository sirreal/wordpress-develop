<?php
namespace HtmlDecoderFuzz;

/**
 * HTML entity decoding oracles.
 */
class Oracles {
	/** @var array<int, array{type: string, oracle: string, detail: string}> */
	private array $events = array();

	private bool $dom_available = false;
	private bool $entity_decode_available = true;
	private bool $mb_available = false;

	public static function build(): self {
		$oracles = new self();

		$oracles->dom_available           = class_exists( \Dom\HTMLDocument::class );
		$oracles->entity_decode_available = function_exists( 'html_entity_decode' ) && defined( 'ENT_HTML5' ) && defined( 'ENT_QUOTES' );
		$oracles->mb_available            = function_exists( 'mb_check_encoding' );

		if ( ! $oracles->dom_available ) {
			$oracles->events[] = array(
				'type'   => 'oracle-unavailable',
				'oracle' => 'dom',
				'detail' => 'PHP 8.4 Dom\\HTMLDocument is required',
			);
		}

		if ( ! $oracles->entity_decode_available ) {
			$oracles->events[] = array(
				'type'   => 'oracle-unavailable',
				'oracle' => 'entity-decode',
				'detail' => 'html_entity_decode with ENT_HTML5 and ENT_QUOTES is required',
			);
		}

		if ( ! $oracles->mb_available ) {
			$oracles->events[] = array(
				'type'   => 'oracle-unavailable',
				'oracle' => 'mb',
				'detail' => 'mb_check_encoding is required for UTF-8 output checks',
			);
		}

		if ( $oracles->dom_available ) {
			$oracles->verify_battery();
		}

		if ( $oracles->entity_decode_available ) {
			$oracles->verify_entity_decode_battery();
		}

		return $oracles;
	}

	/**
	 * @return array<int, array{0: string, 1: string, 2: string}> [context, payload, expected]
	 */
	public static function battery(): array {
		return array(
			array( 'text', '', '' ),
			array( 'attribute', '', '' ),
			array( 'text', 'plain text', 'plain text' ),
			array( 'attribute', 'plain text', 'plain text' ),
			array( 'text', '&amp;', '&' ),
			array( 'attribute', '&amp;', '&' ),
			array( 'text', '&amp;amp;', '&amp;' ),
			array( 'attribute', '&amp;amp;', '&amp;' ),
			array( 'text', '&amp', '&' ),
			array( 'attribute', '&amp', '&' ),
			array( 'text', '&ampx', '&x' ),
			array( 'attribute', '&ampx', '&ampx' ),
			array( 'text', '&notin;', "\u{2209}" ),
			array( 'attribute', '&notin;', "\u{2209}" ),
			array( 'text', '&notin', "\u{00AC}" . 'in' ),
			array( 'attribute', '&notin', '&notin' ),
			array( 'text', '&NoSuchEntity;', '&NoSuchEntity;' ),
			array( 'attribute', '&NoSuchEntity;', '&NoSuchEntity;' ),
			array( 'text', '&#x80;', "\u{20AC}" ),
			array( 'attribute', '&#x80;', "\u{20AC}" ),
			array( 'text', '&#128;', "\u{20AC}" ),
			array( 'attribute', '&#128;', "\u{20AC}" ),
			array( 'text', '&#0;', "\u{FFFD}" ),
			array( 'attribute', '&#0;', "\u{FFFD}" ),
			array( 'text', '&#xD800;', "\u{FFFD}" ),
			array( 'attribute', '&#xD800;', "\u{FFFD}" ),
			array( 'text', '&#x110000;', "\u{FFFD}" ),
			array( 'attribute', '&#x110000;', "\u{FFFD}" ),
			array( 'text', '&#;', '&#;' ),
			array( 'attribute', '&#;', '&#;' ),
			array( 'text', '&#x;', '&#x;' ),
			array( 'attribute', '&#x;', '&#x;' ),
			array( 'text', 'a&#0000058b', 'a:b' ),
			array( 'attribute', 'a&#0000058b', 'a:b' ),
		);
	}

	public function has_required(): bool {
		return $this->dom_available && $this->entity_decode_available && $this->mb_available;
	}

	public function names(): array {
		$names = array();
		if ( $this->dom_available ) {
			$names[] = 'dom';
		}
		if ( $this->entity_decode_available ) {
			$names[] = 'entity-decode';
		}
		if ( $this->mb_available ) {
			$names[] = 'mb';
		}
		return $names;
	}

	/** @return array<int, array{type: string, oracle: string, detail: string}> */
	public function drain_events(): array {
		$events       = $this->events;
		$this->events = array();
		return $events;
	}

	public function decode( string $context, string $payload ): string {
		if ( 'text' === $context ) {
			return $this->decode_text( $payload );
		}

		if ( 'attribute' === $context ) {
			return $this->decode_attribute( $payload );
		}

		throw new \InvalidArgumentException( "Unknown context {$context}" );
	}

	public function decode_text_with_entity_decode( string $payload ): ?string {
		if ( ! $this->entity_decode_available || ! self::supports_entity_decode_text_payload( $payload ) ) {
			return null;
		}

		return html_entity_decode( $payload, ENT_HTML5 | ENT_QUOTES, 'UTF-8' );
	}

	private function decode_text( string $payload ): string {
		$document = $this->parse( '<!DOCTYPE html><html><head><meta charset="utf-8"></head><body><div id="fuzz">' . $payload . '</div></body></html>' );
		$div      = $document->getElementById( 'fuzz' );
		if ( null === $div ) {
			throw new \RuntimeException( 'DOM oracle could not find text wrapper element.' );
		}

		return $div->textContent;
	}

	private function decode_attribute( string $payload ): string {
		$document = $this->parse( '<!DOCTYPE html><html><head><meta charset="utf-8"></head><body><div id="fuzz" title="' . $payload . '"></div></body></html>' );
		$div      = $document->getElementById( 'fuzz' );
		if ( null === $div ) {
			throw new \RuntimeException( 'DOM oracle could not find attribute wrapper element.' );
		}

		return $div->getAttribute( 'title' );
	}

	private function parse( string $html ): \Dom\HTMLDocument {
		$document = @\Dom\HTMLDocument::createFromString( $html );
		if ( ! $document instanceof \Dom\HTMLDocument ) {
			throw new \RuntimeException( 'DOM oracle parse failed.' );
		}

		return $document;
	}

	private static function supports_entity_decode_text_payload( string $payload ): bool {
		$length = strlen( $payload );
		$offset = 0;

		while ( false !== ( $amp_at = strpos( $payload, '&', $offset ) ) ) {
			$name_at = $amp_at + 1;
			if ( $name_at >= $length ) {
				return true;
			}

			if ( '#' === $payload[ $name_at ] ) {
				return false;
			}

			$name_length = strspn( $payload, '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz', $name_at );
			if ( 0 === $name_length ) {
				$offset = $name_at;
				continue;
			}

			$after_name = $name_at + $name_length;
			if ( $after_name >= $length || ';' !== $payload[ $after_name ] ) {
				return false;
			}

			$reference_name = substr( $payload, $name_at, $name_length + 1 );
			if ( ! isset( self::entity_decode_named_reference_set()[ $reference_name ] ) ) {
				return false;
			}

			$offset = $after_name + 1;
		}

		return true;
	}

	/**
	 * @return array<string, true>
	 */
	private static function entity_decode_named_reference_set(): array {
		static $names = null;
		if ( null !== $names ) {
			return $names;
		}

		$names = array();
		foreach ( Bootstrap::named_reference_names() as $name ) {
			if ( str_ends_with( $name, ';' ) ) {
				$names[ $name ] = true;
			}
		}

		return $names;
	}

	private function verify_battery(): void {
		foreach ( self::battery() as $i => $vector ) {
			list( $context, $payload, $expected ) = $vector;
			try {
				$got = $this->decode( $context, $payload );
			} catch ( \Throwable $error ) {
				$this->dom_available = false;
				$this->events[]      = array(
					'type'   => 'oracle-disabled',
					'oracle' => 'dom',
					'detail' => "battery vector {$i} threw " . get_class( $error ) . ': ' . $error->getMessage(),
				);
				return;
			}

			if ( $got !== $expected ) {
				$this->dom_available = false;
				$this->events[]      = array(
					'type'   => 'oracle-disabled',
					'oracle' => 'dom',
					'detail' => sprintf(
						'battery vector %d (%s, %s): expected %s, got %s',
						$i,
						$context,
						bin2hex( $payload ),
						bin2hex( $expected ),
						bin2hex( $got )
					),
				);
				return;
			}
		}
	}

	private function verify_entity_decode_battery(): void {
		$battery = array(
			array( '', '' ),
			array( 'plain text', 'plain text' ),
			array( 'a&amp;b', 'a&b' ),
			array( '&quot;&apos;', "\"'" ),
			array( '&notin;', "\u{2209}" ),
			array( '&nvlt;', "<\u{20D2}" ),
			array( '&NewLine;', "\n" ),
		);

		foreach ( $battery as $i => $vector ) {
			list( $payload, $expected ) = $vector;
			try {
				$got = $this->decode_text_with_entity_decode( $payload );
			} catch ( \Throwable $error ) {
				$this->entity_decode_available = false;
				$this->events[]                = array(
					'type'   => 'oracle-disabled',
					'oracle' => 'entity-decode',
					'detail' => "battery vector {$i} threw " . get_class( $error ) . ': ' . $error->getMessage(),
				);
				return;
			}

			if ( $got !== $expected ) {
				$this->entity_decode_available = false;
				$this->events[]                = array(
					'type'   => 'oracle-disabled',
					'oracle' => 'entity-decode',
					'detail' => sprintf(
						'battery vector %d (%s): expected %s, got %s',
						$i,
						bin2hex( $payload ),
						bin2hex( $expected ),
						bin2hex( is_string( $got ) ? $got : '' )
					),
				);
				return;
			}
		}
	}
}
