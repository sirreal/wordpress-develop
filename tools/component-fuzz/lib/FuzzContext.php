<?php
namespace ComponentFuzz;

final class FuzzContext {
	private int $seed;
	private string $surface;
	private int $iteration;
	private Prng $prng;

	public function __construct( int $seed, string $surface = 'component', int $iteration = 0 ) {
		$this->seed      = $seed;
		$this->surface   = $surface;
		$this->iteration = $iteration;
		$this->prng      = new Prng( $seed );
	}

	public function seed(): int {
		return $this->seed;
	}

	public function surface(): string {
		return $this->surface;
	}

	public function iteration(): int {
		return $this->iteration;
	}

	public function fork( string $label ): self {
		return new self( Prng::mix_seed( $this->seed, $label ), $this->surface, $this->iteration );
	}

	public function int( int $min, int $max ): int {
		return $this->prng->int( $min, $max );
	}

	public function bool( int $true_percent = 50 ): bool {
		return $this->prng->bool( $true_percent );
	}

	public function choice( array $values ) {
		return $this->prng->choice( $values );
	}

	public function weightedChoice( array $weighted_values ) {
		return $this->prng->weighted_choice( $weighted_values );
	}

	public function bytes( int $min = 0, int $max = 64 ): string {
		$length = $this->int( $min, $max );
		$out    = '';
		for ( $i = 0; $i < $length; $i++ ) {
			$out .= chr( $this->int( 0, 255 ) );
		}
		return $out;
	}

	public function ascii( int $min = 0, int $max = 64 ): string {
		$length = $this->int( $min, $max );
		$out    = '';
		for ( $i = 0; $i < $length; $i++ ) {
			$out .= chr( $this->int( 32, 126 ) );
		}
		return $out;
	}

	public function text( int $min = 0, int $max = 64 ): string {
		$pieces = array(
			$this->ascii( $min, $max ),
			$this->choice( array( '', 'é', '☃', 'مرحبا', '中文', "line\nbreak", "tab\tvalue" ) ),
			$this->bool( 25 ) ? $this->bytes( 0, min( 12, $max ) ) : '',
		);

		return substr( implode( '', $pieces ), 0, $max );
	}

	public function identifier( int $min = 1, int $max = 16 ): string {
		$first = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ_';
		$rest  = $first . '0123456789-:';
		$len   = $this->int( $min, $max );
		$out   = $first[ $this->int( 0, strlen( $first ) - 1 ) ];
		for ( $i = 1; $i < $len; $i++ ) {
			$out .= $rest[ $this->int( 0, strlen( $rest ) - 1 ) ];
		}
		return $out;
	}

	public function url(): string {
		$scheme = $this->choice( array( 'http', 'https', 'ftp', 'mailto', 'data', 'javascript', 'file', 'irc', '' ) );
		$host   = $this->choice(
			array(
				'example.com',
				'localhost',
				'127.0.0.1',
				'[::1]',
				'xn--bcher-kva.example',
				$this->identifier( 3, 12 ) . '.test',
			)
		);
		$segments = array();
		$count    = $this->int( 0, 3 );
		for ( $i = 0; $i < $count; $i++ ) {
			$segments[] = rawurlencode( $this->text( 0, 10 ) );
		}
		$path   = '/' . implode( '/', $segments );
		$query  = $this->bool() ? '?q=' . rawurlencode( $this->text( 0, 16 ) ) : '';
		$prefix = '' === $scheme ? '' : $scheme . ':';

		if ( in_array( $scheme, array( 'mailto', 'javascript', 'data' ), true ) ) {
			return $prefix . $this->text( 0, 48 );
		}

		return $prefix . '//' . $host . $path . $query;
	}

	public function filename(): string {
		$base = $this->choice( array( 'image', 'archive', 'index', '../escape', '..\\escape', 'résumé', $this->identifier( 1, 12 ) ) );
		$ext  = $this->choice( array( 'jpg', 'png', 'php', 'txt', 'tar.gz', 'svg', 'webp', '', 'PhP' ) );
		$name = '' === $ext ? $base : $base . '.' . $ext;
		if ( $this->bool( 20 ) ) {
			$name .= chr( 0 ) . '.jpg';
		}
		if ( $this->bool( 20 ) ) {
			$name = str_replace( '/', '\\', $name );
		}
		return $name;
	}

	public function htmlFragment( int $max_depth = 3 ): string {
		$tags = array( 'a', 'p', 'div', 'span', 'img', 'svg', 'math', 'script', 'style', 'template', 'button', 'form' );
		$out  = '';
		$open = array();
		$count = $this->int( 1, 8 );
		for ( $i = 0; $i < $count; $i++ ) {
			if ( count( $open ) > 0 && $this->bool( 25 ) ) {
				$out .= '</' . array_pop( $open ) . '>';
				continue;
			}

			$tag = $this->choice( $tags );
			$out .= '<' . $tag;
			$attribute_count = $this->int( 0, 4 );
			for ( $j = 0; $j < $attribute_count; $j++ ) {
				$name  = $this->choice( array( 'href', 'src', 'style', 'class', 'id', 'onclick', 'data-x', $this->identifier( 1, 10 ) ) );
				$value = 'href' === $name || 'src' === $name ? $this->url() : $this->text( 0, 24 );
				$out  .= ' ' . $name . '="' . str_replace( '"', '&quot;', $value ) . '"';
			}
			$out .= $this->bool( 15 ) ? '/>' : '>';
			if ( ! str_ends_with( $out, '/>' ) && count( $open ) < $max_depth ) {
				$open[] = $tag;
			}
			$out .= $this->text( 0, 32 );
		}

		while ( $open && $this->bool( 70 ) ) {
			$out .= '</' . array_pop( $open ) . '>';
		}

		return $out;
	}

	public function jsonValue( int $depth = 0 ) {
		if ( $depth > 3 ) {
			return $this->choice( array( null, true, false, $this->int( -100, 100 ), $this->text( 0, 24 ) ) );
		}

		$type = $this->choice( array( 'null', 'bool', 'int', 'float', 'string', 'array', 'object' ) );
		if ( 'null' === $type ) {
			return null;
		}
		if ( 'bool' === $type ) {
			return $this->bool();
		}
		if ( 'int' === $type ) {
			return $this->int( -100000, 100000 );
		}
		if ( 'float' === $type ) {
			return $this->int( -100000, 100000 ) / max( 1, $this->int( 1, 1000 ) );
		}
		if ( 'string' === $type ) {
			return $this->text( 0, 48 );
		}
		if ( 'array' === $type ) {
			$out = array();
			$count = $this->int( 0, 4 );
			for ( $i = 0; $i < $count; $i++ ) {
				$out[] = $this->jsonValue( $depth + 1 );
			}
			return $out;
		}

		$out = array();
		$count = $this->int( 0, 4 );
		for ( $i = 0; $i < $count; $i++ ) {
			$out[ $this->identifier( 1, 8 ) ] = $this->jsonValue( $depth + 1 );
		}
		return $out;
	}

	public function result( string $invariant, bool $ok, array $data = array(), ?string $status = null ): array {
		return array(
			'ok'        => $ok,
			'status'    => $status ?? ( $ok ? 'passed' : 'failed' ),
			'surface'   => $this->surface,
			'invariant' => $invariant,
			'seed'      => $this->seed,
			'iteration' => $this->iteration,
			'data'      => $this->compact_data( $data ),
		);
	}

	public function pass( string $invariant, array $data = array() ): array {
		return $this->result( $invariant, true, $data, 'passed' );
	}

	public function fail( string $invariant, array $data = array() ): array {
		return $this->result( $invariant, false, $data, 'failed' );
	}

	public function skip( string $invariant, string $reason, array $data = array() ): array {
		$data['reason'] = $reason;
		return $this->result( $invariant, true, $data, 'skipped' );
	}

	private function compact_data( array $data ): array {
		foreach ( $data as $key => $value ) {
			$data[ $key ] = preview_value( $value );
		}
		return $data;
	}
}
