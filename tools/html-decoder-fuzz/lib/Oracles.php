<?php
namespace HtmlDecoderFuzz;

/**
 * DOM-backed HTML entity decoding oracle.
 */
class Oracles {
	/** @var array<int, array{type: string, oracle: string, detail: string}> */
	private array $events = array();

	private bool $dom_available = false;
	private bool $mb_available = false;

	public static function build(): self {
		$oracles = new self();

		$oracles->dom_available = class_exists( \Dom\HTMLDocument::class );
		$oracles->mb_available  = function_exists( 'mb_check_encoding' );

		if ( ! $oracles->dom_available ) {
			$oracles->events[] = array(
				'type'   => 'oracle-unavailable',
				'oracle' => 'dom',
				'detail' => 'PHP 8.4 Dom\\HTMLDocument is required',
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
		return $this->dom_available && $this->mb_available;
	}

	public function names(): array {
		$names = array();
		if ( $this->dom_available ) {
			$names[] = 'dom';
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
}
