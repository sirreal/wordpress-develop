<?php
/**
 * Property tests covering WP_Token_Map functionality.
 *
 * @package WordPress
 *
 * @since 6.6.0
 * @group html-api-token-map
 * @group token-map
 *
 * @coversDefaultClass WP_Token_Map
 */
class Tests_WpTokenMapProperties extends WP_UnitTestCase {
	/**
	 * Ensure generated contains() probes agree with a naive reference lookup.
	 *
	 * @ticket 60698
	 *
	 * @dataProvider data_generated_token_sets
	 *
	 * @param array $mappings   Generated token mappings.
	 * @param int   $key_length Group key length for the generated map.
	 * @param int   $seed       Seed used to generate the token set.
	 */
	public function test_contains_matches_reference_for_generated_token_sets( $mappings, $key_length, $seed ) {
		$map = WP_Token_Map::from_array( $mappings, $key_length );
		$this->assertInstanceOf( WP_Token_Map::class, $map );

		foreach ( self::contains_probes( $mappings, $seed ) as $probe ) {
			foreach ( self::case_sensitivities() as $case_sensitivity ) {
				$this->assert_contains_matches_reference( $map, $mappings, $probe, $key_length, $seed, $case_sensitivity, 'contains' );
			}
		}
	}

	/**
	 * Ensure generated read_token() probes agree with a naive reference lookup.
	 *
	 * @ticket 60698
	 *
	 * @dataProvider data_generated_token_sets
	 *
	 * @param array $mappings   Generated token mappings.
	 * @param int   $key_length Group key length for the generated map.
	 * @param int   $seed       Seed used to generate the token set.
	 */
	public function test_read_token_matches_reference_for_generated_documents( $mappings, $key_length, $seed ) {
		$map = WP_Token_Map::from_array( $mappings, $key_length );
		$this->assertInstanceOf( WP_Token_Map::class, $map );

		foreach ( self::generated_documents( $mappings, $seed ) as $document_index => $document ) {
			$document_length = strlen( $document );

			for ( $offset = 0; $offset <= $document_length; $offset++ ) {
				foreach ( self::case_sensitivities() as $case_sensitivity ) {
					$this->assert_read_token_matches_reference(
						$map,
						$mappings,
						$document,
						$offset,
						$key_length,
						$seed,
						$case_sensitivity,
						"read_token document {$document_index}"
					);
				}
			}
		}
	}

	/**
	 * Ensure generated nested-prefix families match greedily.
	 *
	 * @ticket 60698
	 *
	 * @dataProvider data_key_lengths
	 *
	 * @param int $key_length Group key length for the generated map.
	 */
	public function test_generated_nested_prefix_families_match_longest_token( $key_length ) {
		$mappings = array();
		$token    = '';
		foreach ( array( 'a', 'b', 'c', 'D', ';', "\x80", 'e', 'f' ) as $chunk ) {
			$token             .= $chunk;
			$mappings[ $token ] = 'value-' . strlen( $token );
		}

		$map = WP_Token_Map::from_array( $mappings, $key_length );
		$this->assertInstanceOf( WP_Token_Map::class, $map );

		$document = "{$token} suffix";
		$length   = null;
		$this->assertSame( $mappings[ $token ], $map->read_token( $document, 0, $length ) );
		$this->assertSame( strlen( $token ), $length );
	}

	/**
	 * Ensure generated maps preserve behavior after to_array()/from_array().
	 *
	 * @ticket 60698
	 *
	 * @dataProvider data_generated_token_sets
	 *
	 * @param array $mappings   Generated token mappings.
	 * @param int   $key_length Group key length for the generated map.
	 * @param int   $seed       Seed used to generate the token set.
	 */
	public function test_generated_maps_round_trip_through_array_export( $mappings, $key_length, $seed ) {
		$map = WP_Token_Map::from_array( $mappings, $key_length );
		$this->assertInstanceOf( WP_Token_Map::class, $map );

		$round_tripped = WP_Token_Map::from_array( $map->to_array(), $key_length );
		$this->assertInstanceOf( WP_Token_Map::class, $round_tripped );

		$this->assert_map_behavior_matches_reference( $round_tripped, $mappings, $key_length, $seed, 'to_array round-trip' );
	}

	/**
	 * Ensure generated maps preserve behavior after precomputed table export.
	 *
	 * @ticket 60698
	 *
	 * @dataProvider data_generated_token_sets
	 *
	 * @param array $mappings   Generated token mappings.
	 * @param int   $key_length Group key length for the generated map.
	 * @param int   $seed       Seed used to generate the token set.
	 */
	public function test_generated_maps_round_trip_through_precomputed_source_table( $mappings, $key_length, $seed ) {
		$map = WP_Token_Map::from_array( $mappings, $key_length );
		$this->assertInstanceOf( WP_Token_Map::class, $map );

		$source_table = $map->precomputed_php_source_table();
		// phpcs:ignore Squiz.PHP.Eval.Discouraged -- This verifies generated source round-trips.
		$round_tripped = eval( "return {$source_table};" );
		$this->assertInstanceOf( WP_Token_Map::class, $round_tripped );

		$this->assert_map_behavior_matches_reference( $round_tripped, $mappings, $key_length, $seed, 'precomputed table round-trip' );
	}

	/**
	 * Ensure ASCII-insensitive matching leaves non-ASCII bytes literal.
	 *
	 * @ticket 60698
	 */
	public function test_ascii_case_insensitive_matching_keeps_non_ascii_bytes_literal() {
		$mappings = array(
			"alpha\xE9"     => 'latin-1-lower',
			"bravo\xC3\xA9" => 'utf-8-lower',
			"charlie\x80Z"  => 'raw-byte',
		);
		$map      = WP_Token_Map::from_array( $mappings, 2 );

		$this->assertTrue( $map->contains( "ALPHA\xE9", 'ascii-case-insensitive' ) );
		$this->assertFalse( $map->contains( "ALPHA\xC9", 'ascii-case-insensitive' ) );
		$this->assertTrue( $map->contains( "BRAVO\xC3\xA9", 'ascii-case-insensitive' ) );
		$this->assertFalse( $map->contains( "BRAVO\xC3\x89", 'ascii-case-insensitive' ) );

		$length = null;
		$this->assertSame( 'raw-byte', $map->read_token( "CHARLIE\x80z", 0, $length, 'ascii-case-insensitive' ) );
		$this->assertSame( strlen( "charlie\x80Z" ), $length );

		$length = null;
		$this->assertNull( $map->read_token( "CHARLIE\x81z", 0, $length, 'ascii-case-insensitive' ) );
		$this->assertNull( $length );
	}

	/**
	 * Ensure array export preserves one-byte group keys.
	 *
	 * This is the minimized regression for generated key_length=1 maps.
	 *
	 * @ticket 60698
	 */
	public function test_array_export_preserves_single_byte_group_keys() {
		$mappings = array(
			'a'  => 'short',
			'ab' => 'long',
			'ac' => 'sibling',
		);
		$map      = WP_Token_Map::from_array( $mappings, 1 );

		$expected = $mappings;
		$actual   = $map->to_array();
		ksort( $expected );
		ksort( $actual );

		$this->assertSame( $expected, $actual );
	}

	/**
	 * Ensure ASCII-insensitive reads work for short tokens.
	 *
	 * This is the minimized regression for generated case-insensitive short
	 * token probes.
	 *
	 * @ticket 60698
	 */
	public function test_ascii_case_insensitive_reads_short_tokens() {
		$map    = WP_Token_Map::from_array( array( 'ab' => 'short-token' ), 2 );
		$length = null;

		$this->assertSame( 'short-token', $map->read_token( 'AB', 0, $length, 'ascii-case-insensitive' ) );
		$this->assertSame( 2, $length );
	}

	/**
	 * Ensure ASCII-insensitive reads check every folded-equivalent group key.
	 *
	 * @ticket 60698
	 *
	 * @dataProvider data_ascii_case_insensitive_group_key_collisions
	 *
	 * @param array  $mappings   Token mappings with folded-equivalent group keys.
	 * @param int    $key_length Group key length for the generated map.
	 * @param string $probe      Probe text.
	 * @param string $expected   Expected mapping.
	 */
	public function test_ascii_case_insensitive_reads_folded_group_key_collisions( $mappings, $key_length, $probe, $expected ) {
		$map    = WP_Token_Map::from_array( $mappings, $key_length );
		$length = null;

		$this->assertTrue( $map->contains( $probe, 'ascii-case-insensitive' ) );
		$this->assertSame( $expected, $map->read_token( $probe, 0, $length, 'ascii-case-insensitive' ) );
		$this->assertSame( strlen( $probe ), $length );
	}

	/**
	 * Ensure generated PHP source escapes tokens and mappings safely.
	 *
	 * @ticket 60698
	 */
	public function test_precomputed_source_table_escapes_php_string_and_comment_bytes() {
		$mappings = array(
			'quote"token'       => 'quote"value',
			'slash\\token'      => 'slash\\value',
			'dollar$token'      => 'dollar$value',
			"control\ntoken"    => "control\nvalue",
			'close?>tag'        => 'close?>value',
			"high\x80\xFFtoken" => "high\x80\xFFvalue",
		);
		$map      = WP_Token_Map::from_array( $mappings, 2 );

		$source_table = $map->precomputed_php_source_table();
		// phpcs:ignore Squiz.PHP.Eval.Discouraged -- This verifies generated source round-trips.
		$round_tripped = eval( "return {$source_table};" );

		$this->assertInstanceOf( WP_Token_Map::class, $round_tripped );
		$this->assertSame( $map->to_array(), $round_tripped->to_array() );
	}

	/**
	 * Ensure short-token reads do not consume missing bytes.
	 *
	 * @ticket 60698
	 */
	public function test_short_token_reads_ignore_text_shorter_than_token() {
		$map    = WP_Token_Map::from_array( array( 'ab' => 'short-token' ), 2 );
		$length = null;

		$this->assertNull( $map->read_token( 'a', 0, $length ) );
		$this->assertNull( $length );

		$length = null;
		$this->assertNull( $map->read_token( '', 0, $length ) );
		$this->assertNull( $length );
	}

	/**
	 * Data provider.
	 *
	 * @return array[].
	 */
	public static function data_generated_token_sets() {
		$cases = array(
			'seed 539231511 key_length 1' => array( 539231511, 1, 70 ),
			'seed 539231512 key_length 2' => array( 539231512, 2, 90 ),
			'seed 867530901 key_length 1' => array( 867530901, 1, 60 ),
			'seed 867530902 key_length 2' => array( 867530902, 2, 80 ),
		);

		foreach ( $cases as $name => $case ) {
			list( $seed, $key_length, $target_count ) = $case;
			yield $name => array( self::generate_token_set( $seed, $key_length, $target_count ), $key_length, $seed );
		}
	}

	/**
	 * Data provider.
	 *
	 * @return array[].
	 */
	public static function data_key_lengths() {
		return array(
			'key length 1' => array( 1 ),
			'key length 2' => array( 2 ),
		);
	}

	/**
	 * Data provider.
	 *
	 * @return array[].
	 */
	public static function data_ascii_case_insensitive_group_key_collisions() {
		return array(
			'key length 1' => array(
				array(
					'Ab' => 'upper-group',
					'aa' => 'lower-group',
				),
				1,
				'aa',
				'lower-group',
			),
			'key length 2' => array(
				array(
					'Abc' => 'mixed-group-one',
					'aBd' => 'mixed-group-two',
				),
				2,
				'abd',
				'mixed-group-two',
			),
		);
	}

	/**
	 * Assert that a token map behaves like the reference implementation.
	 *
	 * @param WP_Token_Map $map        Token map under test.
	 * @param array        $mappings   Generated token mappings.
	 * @param int          $key_length Group key length for the generated map.
	 * @param int          $seed       Seed used to generate the token set.
	 * @param string       $label      Describes the map under test.
	 */
	private function assert_map_behavior_matches_reference( $map, $mappings, $key_length, $seed, $label ) {
		foreach ( self::contains_probes( $mappings, $seed ) as $probe ) {
			foreach ( self::case_sensitivities() as $case_sensitivity ) {
				$this->assert_contains_matches_reference( $map, $mappings, $probe, $key_length, $seed, $case_sensitivity, "{$label} contains" );
			}
		}

		foreach ( self::generated_documents( $mappings, $seed ) as $document_index => $document ) {
			$document_length = strlen( $document );
			for ( $offset = 0; $offset <= $document_length; $offset++ ) {
				foreach ( self::case_sensitivities() as $case_sensitivity ) {
					$this->assert_read_token_matches_reference(
						$map,
						$mappings,
						$document,
						$offset,
						$key_length,
						$seed,
						$case_sensitivity,
						"{$label} read_token document {$document_index}"
					);
				}
			}
		}
	}

	/**
	 * Assert contains() behavior against the reference implementation.
	 *
	 * @param WP_Token_Map $map              Token map under test.
	 * @param array        $mappings         Generated token mappings.
	 * @param string       $probe            Probe word.
	 * @param int          $key_length       Group key length for the generated map.
	 * @param int          $seed             Seed used to generate the token set.
	 * @param string       $case_sensitivity Case sensitivity mode.
	 * @param string       $operation        Operation being tested.
	 */
	private function assert_contains_matches_reference( $map, $mappings, $probe, $key_length, $seed, $case_sensitivity, $operation ) {
		$expected = self::reference_contains( $mappings, $probe, $case_sensitivity );
		$actual   = $map->contains( $probe, $case_sensitivity );

		if ( $expected !== $actual ) {
			$this->assertSame(
				$expected,
				$actual,
				self::failure_context( $mappings, $key_length, $seed, $case_sensitivity, $operation, $probe )
			);
		}
	}

	/**
	 * Assert read_token() behavior against the reference implementation.
	 *
	 * @param WP_Token_Map $map              Token map under test.
	 * @param array        $mappings         Generated token mappings.
	 * @param string       $document         Document to probe.
	 * @param int          $offset           Offset at which to probe.
	 * @param int          $key_length       Group key length for the generated map.
	 * @param int          $seed             Seed used to generate the token set.
	 * @param string       $case_sensitivity Case sensitivity mode.
	 * @param string       $operation        Operation being tested.
	 */
	private function assert_read_token_matches_reference( $map, $mappings, $document, $offset, $key_length, $seed, $case_sensitivity, $operation ) {
		$expected        = self::reference_read_token( $mappings, $document, $offset, $case_sensitivity );
		$actual_length   = null;
		$actual_response = $map->read_token( $document, $offset, $actual_length, $case_sensitivity );

		if ( $expected['value'] !== $actual_response ) {
			$this->assertSame(
				$expected['value'],
				$actual_response,
				self::failure_context( $mappings, $key_length, $seed, $case_sensitivity, $operation, $document, $offset ) . '; response'
			);
		}

		if ( $expected['length'] !== $actual_length ) {
			$this->assertSame(
				$expected['length'],
				$actual_length,
				self::failure_context( $mappings, $key_length, $seed, $case_sensitivity, $operation, $document, $offset ) . '; matched length'
			);
		}
	}

	/**
	 * Return case-sensitivity modes used by the public API.
	 *
	 * @return string[] Case-sensitivity modes.
	 */
	private static function case_sensitivities() {
		return array( 'case-sensitive', 'ascii-case-insensitive' );
	}

	/**
	 * Generate a deterministic token set.
	 *
	 * NUL is excluded from generated tokens because the implementation treats
	 * lookup words containing NUL as invalid. Probe words and documents include
	 * NUL so failed lookups still exercise that byte.
	 *
	 * @param int $seed         Seed used to generate the token set.
	 * @param int $key_length   Group key length for the generated map.
	 * @param int $target_count Number of generated tokens to target.
	 * @return array Generated token mappings.
	 */
	private static function generate_token_set( $seed, $key_length, $target_count ) {
		$state    = $seed;
		$mappings = array();

		self::add_token( $mappings, 'a', $seed );
		self::add_token( $mappings, 'B', $seed );
		if ( $key_length > 1 ) {
			self::add_token( $mappings, 'c', $seed );
		}
		self::add_token( $mappings, str_repeat( 'k', $key_length ), $seed );
		self::add_token( $mappings, str_repeat( 'L', 255 ), $seed );
		self::add_token( $mappings, "hi\x80A;", $seed );
		self::add_token( $mappings, "jo\xFFb;", $seed );
		self::add_token( $mappings, "utf\xC3\xA9;", $seed );
		self::add_token( $mappings, "euro\xE2\x82\xAC;", $seed );
		if ( 1 === $key_length ) {
			self::add_token( $mappings, 'Ab', $seed );
			self::add_token( $mappings, 'aa', $seed );
		} else {
			self::add_token( $mappings, 'Abc', $seed );
			self::add_token( $mappings, 'aBd', $seed );
		}

		$nested = '';
		foreach ( array( 'p', 'r', 'e', 'F', 'i', 'x', ';', "\x80", 'z' ) as $chunk ) {
			$nested .= $chunk;
			self::add_token( $mappings, $nested, $seed );
		}

		$group_key = 1 === $key_length ? 'g' : 'gy';
		for ( $i = 0; $i < 24; $i++ ) {
			self::add_token( $mappings, $group_key . self::random_token_suffix( $state, 2 + ( $i % 7 ) ), $seed );
		}

		$attempts = 0;
		while ( count( $mappings ) < $target_count && $attempts < $target_count * 40 ) {
			self::add_token( $mappings, self::random_token( $state, $key_length, $attempts ), $seed );
			++$attempts;
		}

		return $mappings;
	}

	/**
	 * Add a token to the generated map if it is unambiguous.
	 *
	 * @param array  $mappings Generated token mappings.
	 * @param string $token    Token to add.
	 * @param int    $seed     Seed used to generate the token set.
	 */
	private static function add_token( &$mappings, $token, $seed ) {
		if ( '' === $token || false !== strpos( $token, "\x00" ) || WP_Token_Map::MAX_LENGTH <= strlen( $token ) ) {
			return;
		}

		foreach ( $mappings as $existing_token => $mapping ) {
			if ( self::ascii_lowercase( $existing_token ) === self::ascii_lowercase( $token ) ) {
				return;
			}
		}

		$mappings[ $token ] = 'value-' . $seed . '-' . count( $mappings );
	}

	/**
	 * Generate a token from the allowed byte classes.
	 *
	 * @param int $state      Pseudo-random generator state.
	 * @param int $key_length Group key length for the generated map.
	 * @param int $index      Token index.
	 * @return string Generated token.
	 */
	private static function random_token( &$state, $key_length, $index ) {
		$choice = self::random_int( $state, 0, 9 );
		if ( $choice < 3 && $key_length > 1 ) {
			$target_length = self::random_int( $state, 1, $key_length - 1 );
		} elseif ( $choice < 6 ) {
			$target_length = $key_length;
		} elseif ( $choice < 9 ) {
			$target_length = self::random_int( $state, $key_length + 1, 24 );
		} else {
			$target_length = self::random_int( $state, 48, 96 );
		}

		$token = chr( ord( 'm' ) + ( $index % 10 ) );
		while ( strlen( $token ) < $target_length ) {
			$token .= self::random_token_chunk( $state );
		}

		return substr( $token, 0, $target_length );
	}

	/**
	 * Generate a random suffix.
	 *
	 * @param int $state         Pseudo-random generator state.
	 * @param int $target_length Target byte length.
	 * @return string Generated suffix.
	 */
	private static function random_token_suffix( &$state, $target_length ) {
		$suffix = '';
		while ( strlen( $suffix ) < $target_length ) {
			$suffix .= self::random_token_chunk( $state );
		}

		return substr( $suffix, 0, $target_length );
	}

	/**
	 * Generate a random token chunk.
	 *
	 * @param int $state Pseudo-random generator state.
	 * @return string Generated chunk.
	 */
	private static function random_token_chunk( &$state ) {
		$chunks = array(
			'a',
			'b',
			'C',
			'D',
			'0',
			'9',
			';',
			"\x80",
			"\xFF",
			"\xC2\xA9",
			"\xE2\x82\xAC",
		);

		return $chunks[ self::random_int( $state, 0, count( $chunks ) - 1 ) ];
	}

	/**
	 * Generate contains() probe words.
	 *
	 * @param array $mappings Generated token mappings.
	 * @param int   $seed     Seed used to generate the token set.
	 * @return string[] Probe words.
	 */
	private static function contains_probes( $mappings, $seed ) {
		$state  = $seed ^ 0x5A5A5A5A;
		$probes = array( '', "\x00", "a\x00", "z\x00z" );

		foreach ( array_keys( $mappings ) as $token ) {
			$probes[] = $token;
			$probes[] = self::swap_ascii_case( $token );
			$probes[] = $token . self::random_probe_byte( $state );
			$probes[] = self::mutate_one_byte( $token, $state );

			if ( strlen( $token ) > 1 ) {
				$probes[] = substr( $token, 0, -1 );
			}

			for ( $length = 1; $length < strlen( $token ); $length++ ) {
				$probes[] = substr( $token, 0, $length );
			}
		}

		for ( $i = 0; $i < 400; $i++ ) {
			$probes[] = self::random_probe_word( $state, self::random_int( $state, 0, 32 ) );
		}

		return array_values( array_unique( $probes, SORT_STRING ) );
	}

	/**
	 * Generate documents for read_token() probes.
	 *
	 * @param array $mappings Generated token mappings.
	 * @param int   $seed     Seed used to generate the token set.
	 * @return string[] Generated documents.
	 */
	private static function generated_documents( $mappings, $seed ) {
		$state  = $seed ^ 0x13572468;
		$tokens = array_keys( $mappings );
		usort( $tokens, array( __CLASS__, 'longest_first_then_alphabetical' ) );

		$documents = array(
			'',
			'prefix' . $tokens[0] . 'suffix',
			self::swap_ascii_case( $tokens[0] ) . "\x00" . $tokens[ count( $tokens ) - 1 ],
		);

		for ( $i = 0; $i < 10; $i++ ) {
			$document = '';
			for ( $j = 0; $j < 32; $j++ ) {
				$token = $tokens[ self::random_int( $state, 0, count( $tokens ) - 1 ) ];
				switch ( self::random_int( $state, 0, 5 ) ) {
					case 0:
						$document .= $token;
						break;

					case 1:
						$document .= self::swap_ascii_case( $token );
						break;

					case 2:
						$document .= substr( $token, 0, self::random_int( $state, 0, strlen( $token ) ) );
						break;

					case 3:
						$document .= self::mutate_one_byte( $token, $state );
						break;

					case 4:
						$document .= $token . self::random_probe_word( $state, self::random_int( $state, 1, 4 ) );
						break;

					default:
						$document .= self::random_probe_word( $state, self::random_int( $state, 1, 8 ) );
						break;
				}
			}

			$documents[] = $document;
		}

		return $documents;
	}

	/**
	 * Reference implementation for contains().
	 *
	 * @param array  $mappings         Generated token mappings.
	 * @param string $word             Probe word.
	 * @param string $case_sensitivity Case sensitivity mode.
	 * @return bool Whether the generated set contains the probe word.
	 */
	private static function reference_contains( $mappings, $word, $case_sensitivity ) {
		if ( 'case-sensitive' === $case_sensitivity ) {
			return array_key_exists( $word, $mappings );
		}

		foreach ( array_keys( $mappings ) as $token ) {
			if ( self::ascii_lowercase( $word ) === self::ascii_lowercase( $token ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Reference implementation for read_token().
	 *
	 * @param array  $mappings         Generated token mappings.
	 * @param string $document         Document to probe.
	 * @param int    $offset           Offset at which to probe.
	 * @param string $case_sensitivity Case sensitivity mode.
	 * @return array Expected response and matched token length.
	 */
	private static function reference_read_token( $mappings, $document, $offset, $case_sensitivity ) {
		$tokens          = array_keys( $mappings );
		$document_length = strlen( $document );
		$ignore_case     = 'ascii-case-insensitive' === $case_sensitivity;
		usort( $tokens, array( __CLASS__, 'longest_first_then_alphabetical' ) );

		foreach ( $tokens as $token ) {
			$token_length = strlen( $token );
			if ( $offset + $token_length > $document_length ) {
				continue;
			}

			$candidate = substr( $document, $offset, $token_length );
			$matches   = $ignore_case
				? self::ascii_lowercase( $candidate ) === self::ascii_lowercase( $token )
				: $candidate === $token;

			if ( $matches ) {
				return array(
					'value'  => $mappings[ $token ],
					'length' => $token_length,
				);
			}
		}

		return array(
			'value'  => null,
			'length' => null,
		);
	}

	/**
	 * Sort longer strings first, then alphabetically.
	 *
	 * @param string $a First string to compare.
	 * @param string $b Second string to compare.
	 * @return int Sort order.
	 */
	private static function longest_first_then_alphabetical( $a, $b ) {
		if ( $a === $b ) {
			return 0;
		}

		$length_a = strlen( $a );
		$length_b = strlen( $b );
		if ( $length_a !== $length_b ) {
			return $length_b - $length_a;
		}

		return strcmp( $a, $b );
	}

	/**
	 * Mutate one byte in a token.
	 *
	 * @param string $token Token to mutate.
	 * @param int    $state Pseudo-random generator state.
	 * @return string Mutated token.
	 */
	private static function mutate_one_byte( $token, &$state ) {
		if ( '' === $token ) {
			return self::random_probe_byte( $state );
		}

		$offset      = self::random_int( $state, 0, strlen( $token ) - 1 );
		$replacement = self::random_probe_byte( $state );
		while ( $replacement === $token[ $offset ] ) {
			$replacement = self::random_probe_byte( $state );
		}

		return substr( $token, 0, $offset ) . $replacement . substr( $token, $offset + 1 );
	}

	/**
	 * Swap ASCII case in a byte string.
	 *
	 * @param string $text Text whose ASCII case should be swapped.
	 * @return string Text with ASCII case swapped.
	 */
	private static function swap_ascii_case( $text ) {
		$output = '';
		$length = strlen( $text );

		for ( $i = 0; $i < $length; $i++ ) {
			$byte = ord( $text[ $i ] );
			if ( 0x41 <= $byte && $byte <= 0x5A ) {
				$output .= chr( $byte + 0x20 );
			} elseif ( 0x61 <= $byte && $byte <= 0x7A ) {
				$output .= chr( $byte - 0x20 );
			} else {
				$output .= $text[ $i ];
			}
		}

		return $output;
	}

	/**
	 * Lowercase ASCII bytes only.
	 *
	 * @param string $text Text to lowercase.
	 * @return string Text with only ASCII uppercase bytes folded to lowercase.
	 */
	private static function ascii_lowercase( $text ) {
		return strtr( $text, 'ABCDEFGHIJKLMNOPQRSTUVWXYZ', 'abcdefghijklmnopqrstuvwxyz' );
	}

	/**
	 * Generate a random probe word.
	 *
	 * @param int $state  Pseudo-random generator state.
	 * @param int $length Target byte length.
	 * @return string Generated word.
	 */
	private static function random_probe_word( &$state, $length ) {
		$word = '';
		while ( strlen( $word ) < $length ) {
			$word .= self::random_probe_byte( $state );
		}

		return substr( $word, 0, $length );
	}

	/**
	 * Generate one random probe byte.
	 *
	 * @param int $state Pseudo-random generator state.
	 * @return string Generated byte.
	 */
	private static function random_probe_byte( &$state ) {
		$bytes = array(
			"\x00",
			'a',
			'Z',
			'4',
			';',
			'_',
			"\x80",
			"\xFF",
			"\xC3",
			"\xA9",
			"\xE2",
			"\x82",
			"\xAC",
		);

		return $bytes[ self::random_int( $state, 0, count( $bytes ) - 1 ) ];
	}

	/**
	 * Deterministic pseudo-random integer.
	 *
	 * @param int $state Pseudo-random generator state.
	 * @param int $min   Minimum value.
	 * @param int $max   Maximum value.
	 * @return int Generated integer.
	 */
	private static function random_int( &$state, $min, $max ) {
		$state = ( ( 1103515245 * $state ) + 12345 ) % 2147483648;

		return $min + ( $state % ( $max - $min + 1 ) );
	}

	/**
	 * Build an actionable assertion failure message.
	 *
	 * @param array       $mappings         Generated token mappings.
	 * @param int         $key_length       Group key length for the generated map.
	 * @param int         $seed             Seed used to generate the token set.
	 * @param string      $case_sensitivity Case sensitivity mode.
	 * @param string      $operation        Operation being tested.
	 * @param string      $probe            Probe word or document.
	 * @param int|null    $offset           Optional offset into the probe.
	 * @return string Assertion failure context.
	 */
	private static function failure_context( $mappings, $key_length, $seed, $case_sensitivity, $operation, $probe, $offset = null ) {
		$context = "Seed {$seed}; key_length {$key_length}; {$operation}; case {$case_sensitivity}; probe " . bin2hex( $probe );
		if ( null !== $offset ) {
			$context .= "; offset {$offset}";
		}

		return $context . '; token_set ' . base64_encode( serialize( $mappings ) );
	}
}
