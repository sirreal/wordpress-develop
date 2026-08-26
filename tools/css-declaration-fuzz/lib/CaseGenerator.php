<?php
namespace CssDeclarationFuzz;

class CaseGenerator {
	/** @return array{bucket:string,style:string,expected:array<int,array{name:string,important:bool}>|null} */
	public static function generate( int $seed ): array {
		$prng   = new Prng( (string) $seed, 'case' );
		$bucket = $prng->weighted(
			array(
				'structured' => 55,
				'mutated'    => 25,
				'eof-repair' => 10,
				'raw-bytes'  => 10,
			)
		);

		if ( 'structured' === $bucket ) {
			return self::structured( $prng, $bucket );
		}

		if ( 'eof-repair' === $bucket ) {
			return self::eof_repair( $prng );
		}

		if ( 'raw-bytes' === $bucket ) {
			return array(
				'bucket'   => $bucket,
				'style'    => self::raw_bytes( $prng, $prng->int( 0, 192 ) ),
				'expected' => null,
			);
		}

		$case  = self::structured( $prng, $bucket );
		$style = self::mutate( $case['style'], $prng );
		return array(
			'bucket'   => $bucket,
			'style'    => $style,
			'expected' => null,
		);
	}

	/** @return array{bucket:string,style:string,expected:array<int,array{name:string,important:bool}>} */
	private static function structured( Prng $prng, string $bucket ): array {
		$count    = $prng->int( 1, 8 );
		$style    = '';
		$expected = array();

		if ( $prng->chance( 30 ) ) {
			$style .= $prng->choice( array( ';', ';; ', 'garbage;', ':bad;', '@ignored x;', '/*lead*/' ) );
		}

		for ( $i = 0; $i < $count; $i++ ) {
			$name      = self::property( $prng );
			$value     = self::valid_value( $prng );
			$important = $prng->chance( 30 );
			$before    = $prng->choice( array( '', ' ', "\t", "\n", '/*a*/', ' /*a*/ ' ) );
			$after     = $prng->choice( array( '', ' ', "\t", '/*b*/', ' /*b*/ ' ) );
			$priority  = '';
			if ( $important ) {
				$priority = $prng->choice( array( ' !important', '!IMPORTANT', ' !/**/important', " !\timportant" ) );
			}

			$style .= $name['raw'] . $before . ':' . $after . $value . $priority;
			if ( $i + 1 < $count || $prng->chance( 80 ) ) {
				$style .= ';';
			}
			$style .= $prng->choice( array( '', ' ', "\n", '/*between*/', ' /*between*/ ' ) );

			$expected[] = array(
				'name'      => $name['decoded'],
				'important' => $important,
			);

			if ( $i + 1 < $count && $prng->chance( 15 ) ) {
				$style .= $prng->choice( array( 'garbage;', ':bad;', '@ignored;', '{};' ) );
			}
		}

		return array(
			'bucket'   => $bucket,
			'style'    => $style,
			'expected' => $expected,
		);
	}

	/** @return array{bucket:string,style:string,expected:array<int,array{name:string,important:bool}>} */
	private static function eof_repair( Prng $prng ): array {
		$name  = self::property( $prng );
		$value = $prng->choice(
			array(
				'var(--x',
				'calc(1px + (2%',
				'[one {two: three',
				'{one: [two',
				'url(image.png',
			)
		);
		return array(
			'bucket' => 'eof-repair',
			'style'  => $name['raw'] . ': ' . $value,
			'expected' => array(
				array(
					'name'      => $name['decoded'],
					'important' => false,
				),
			),
		);
	}

	/** @return array{raw:string,decoded:string} */
	public static function property( Prng $prng ): array {
		return $prng->choice(
			array(
				array( 'raw' => 'color', 'decoded' => 'color' ),
				array( 'raw' => 'COLOR', 'decoded' => 'color' ),
				array( 'raw' => 'background-image', 'decoded' => 'background-image' ),
				array( 'raw' => 'margin-top', 'decoded' => 'margin-top' ),
				array( 'raw' => 'font-family', 'decoded' => 'font-family' ),
				array( 'raw' => '--tone', 'decoded' => '--tone' ),
				array( 'raw' => '--Tone', 'decoded' => '--Tone' ),
				array( 'raw' => 'c\\6f lor', 'decoded' => 'color' ),
				array( 'raw' => '\\63 olor', 'decoded' => 'color' ),
				array( 'raw' => '--\\54 one', 'decoded' => '--Tone' ),
			)
		);
	}

	public static function valid_value( Prng $prng ): string {
		return $prng->choice(
			array(
				'red',
				'10px',
				'-1.25e2ms',
				'calc(100% - 2px)',
				'var(--x, rgb(1 2 3 / .5))',
				'linear-gradient(45deg, red, #00ff00)',
				'"a; !important"',
				"'line\\A break'",
				'url(image.png)',
				'url("x;y.png")',
				'[a;b]',
				'{x:y; z:w}',
				'/*before*/ green /*after*/',
				'attr(data-x type(<number>), 1)',
				'color(display-p3 1 0 0 / 50%)',
			)
		);
	}

	public static function mutation_value( int $seed ): string {
		return 'var(--fuzz-' . ( $seed % 97 ) . ', calc(1px + 2%))';
	}

	public static function append_property( int $seed ): string {
		return 0 === $seed % 3 ? '--Fuzz' . ( $seed % 31 ) : 'fuzz-prop-' . ( $seed % 31 );
	}

	private static function mutate( string $style, Prng $prng ): string {
		$rounds = $prng->int( 1, 5 );
		for ( $round = 0; $round < $rounds; $round++ ) {
			$at = $prng->int( 0, strlen( $style ) );
			switch ( $prng->int( 0, 4 ) ) {
				case 0:
					$style = substr( $style, 0, $at ) . self::raw_bytes( $prng, $prng->int( 1, 8 ) ) . substr( $style, $at );
					break;
				case 1:
					if ( strlen( $style ) > 0 ) {
						$length = $prng->int( 1, min( 12, strlen( $style ) - min( $at, strlen( $style ) - 1 ) ) );
						$at     = min( $at, strlen( $style ) - 1 );
						$style  = substr( $style, 0, $at ) . substr( $style, $at + $length );
					}
					break;
				case 2:
					$style = substr( $style, 0, $at );
					break;
				case 3:
					$style = substr( $style, 0, $at ) . $prng->choice( array( "\0", "\r", "\f", "\xc0", "\xed\xa0\x80", '\\', '/*' ) ) . substr( $style, $at );
					break;
				case 4:
					if ( strlen( $style ) > 0 ) {
						$at    = min( $at, strlen( $style ) - 1 );
						$style = substr( $style, 0, $at ) . str_repeat( $style[ $at ], $prng->int( 2, 8 ) ) . substr( $style, $at + 1 );
					}
					break;
			}
		}
		return substr( $style, 0, 512 );
	}

	private static function raw_bytes( Prng $prng, int $length ): string {
		$alphabet = "abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789-_:;!#.%()[]{}'\"/\\ \t\n\r\f\0";
		$out      = '';
		for ( $i = 0; $i < $length; $i++ ) {
			if ( $prng->chance( 8 ) ) {
				$out .= chr( $prng->int( 0x80, 0xff ) );
			} else {
				$out .= $alphabet[ $prng->int( 0, strlen( $alphabet ) - 1 ) ];
			}
		}
		return $out;
	}
}
