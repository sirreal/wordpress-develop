<?php

/**
 * Class for efficiently looking up and mapping string keys to string values, with limits.
 *
 * @package    WordPress
 * @since      6.6.0
 */

/**
 * WP_Token_Map class.
 *
 * Use this class in specific circumstances with a static set of lookup keys which map to
 * a static set of transformed values. For example, this class is used to map HTML named
 * character references to their equivalent UTF-8 values.
 *
 * This class works differently than code calling `in_array()` and other methods. It
 * internalizes lookup logic and provides helper interfaces to optimize lookup and
 * transformation. It provides a method for precomputing the lookup tables and storing
 * them as PHP source code.
 *
 * All tokens and substitutions must be shorter than 256 bytes.
 *
 * Example:
 *
 *     $smilies = WP_Token_Map::from_array( array(
 *         '8O' => '😯',
 *         ':(' => '🙁',
 *         ':)' => '🙂',
 *         ':?' => '😕',
 *      ) );
 *
 *      true  === $smilies->contains( ':)' );
 *      false === $smilies->contains( 'simile' );
 *
 *      '😕' === $smilies->read_token( 'Not sure :?.', 9, $length_of_smily_syntax );
 *      2    === $length_of_smily_syntax;
 *
 * ## Precomputing the Token Map.
 *
 * Creating the class involves some work sorting and organizing the tokens and their
 * replacement values. In order to skip this, it's possible for the class to export
 * its state and be used as actual PHP source code.
 *
 * Example:
 *
 *      // Export with four spaces as the indent, only for the sake of this docblock.
 *      // The default indent is a tab character.
 *      $indent = '    ';
 *      echo $smilies->precomputed_php_source_table( $indent );
 *
 *      // Output, to be pasted into a PHP source file:
 *      WP_Token_Map::from_precomputed_table(
 *          array(
 *              "storage_version" => "6.6.0",
 *              "key_length" => 2,
 *              "groups" => "",
 *              "long_words" => array(),
 *              "small_words" => "8O\x00:)\x00:(\x00:?\x00",
 *              "small_mappings" => array( "😯", "🙂", "🙁", "😕" )
 *          )
 *      );
 *
 * ## Large vs. small words.
 *
 * This class uses a short prefix called the "key" to optimize lookup of its tokens.
 * This means that some tokens may be shorter than or equal in length to that key.
 * Those words that are longer than the key are called "large" while those shorter
 * than or equal to the key length are called "small."
 *
 * This separation of large and small words is incidental to the way this class
 * optimizes lookup, and should be considered an internal implementation detail
 * of the class. It may still be important to be aware of it, however.
 *
 * ## Determining Key Length.
 *
 * The choice of the size of the key length should be based on the data being stored in
 * the token map. It should divide the data as evenly as possible, but should not create
 * so many groups that a large fraction of the groups only contain a single token.
 *
 * For the HTML5 named character references, a key length of 2 was found to provide a
 * sufficient spread and should be a good default for relatively large sets of tokens.
 *
 * However, for some data sets this might be too long. For example, a list of smilies
 * may be too small for a key length of 2. Perhaps 1 would be more appropriate. It's
 * best to experiment and determine empirically which values are appropriate.
 *
 * ## Generate Pre-Computed Source Code.
 *
 * Since the `WP_Token_Map` is designed for relatively static lookups, it can be
 * advantageous to precompute the values and instantiate a table that has already
 * sorted and grouped the tokens and built the lookup strings.
 *
 * This can be done with `WP_Token_Map::precomputed_php_source_table()`.
 *
 * Note that if there is a leading character that all tokens need, such as `&` for
 * HTML named character references, it can be beneficial to exclude this from the
 * token map. Instead, find occurrences of the leading character and then use the
 * token map to see if the following characters complete the token.
 *
 * Example:
 *
 *     $map = WP_Token_Map::from_array( array( 'simple_smile:' => '🙂', 'sob:' => '😭', 'soba:' => '🍜' ) );
 *     echo $map->precomputed_php_source_table();
 *     // Output
 *     WP_Token_Map::from_precomputed_table(
 *         array(
 *             "storage_version" => "6.6.0",
 *             "key_length" => 2,
 *             "groups" => "si\x00so\x00",
 *             "long_words" => array(
 *                 // simple_smile:[🙂].
 *                 "\x0bmple_smile:\x04🙂",
 *                 // soba:[🍜] sob:[😭].
 *                 "\x03ba:\x04🍜\x02b:\x04😭",
 *             ),
 *             "short_words" => "",
 *             "short_mappings" => array()
 *         }
 *     );
 *
 * This precomputed value can be stored directly in source code and will skip the
 * startup cost of generating the lookup strings. See `$html5_named_character_entities`.
 *
 * Note that any updates to the precomputed format should update the storage version
 * constant. It would also be best to provide an update function to take older known
 * versions and upgrade them in place when loading into `from_precomputed_table()`.
 *
 * ## Future Direction.
 *
 * It may be viable to dynamically increase the length limits such that there's no need to impose them.
 * The limit appears because of the packing structure, which indicates how many bytes each segment of
 * text in the lookup tables spans. If, however, care were taken to track the longest word length, then
 * the packing structure could change its representation to allow for that. Each additional byte storing
 * length, however, increases the memory overhead and lookup runtime.
 *
 * An alternative approach could be to borrow the UTF-8 variable-length encoding and store lengths of less
 * than 127 as a single byte with the high bit unset, storing longer lengths as the combination of
 * continuation bytes.
 *
 * Since it has not been shown during the development of this class that longer strings are required, this
 * update is deferred until such a need is clear.
 *
 * @since 6.6.0
 */
class WP_Token_Map {
	/**
	 * Denotes the version of the code which produces pre-computed source tables.
	 *
	 * This version will be used not only to verify pre-computed data, but also
	 * to upgrade pre-computed data from older versions. Choosing a name that
	 * corresponds to the WordPress release will help people identify where an
	 * old copy of data came from.
	 */
	const STORAGE_VERSION = '6.6.0-trunk';

	/**
	 * Maximum length for each key and each transformed value in the table (in bytes).
	 *
	 * @since 6.6.0
	 */
	const MAX_LENGTH = 256;

	/**
	 * How many bytes of each key are used to form a group key for lookup.
	 * This also determines whether a word is considered short or long.
	 *
	 * @since 6.6.0
	 *
	 * @var int
	 */
	private $key_length = 2;

	/**
	 * Stores an optimized form of the word set, where words are grouped
	 * by a prefix of the `$key_length` and then collapsed into a string.
	 *
	 * In each group, the keys and lookups form a packed data structure.
	 * The keys in the string are stripped of their "group key," which is
	 * the prefix of length `$this->key_length` shared by all of the items
	 * in the group. Each word in the string is prefixed by a single byte
	 * whose raw unsigned integer value represents how many bytes follow.
	 *
	 *     ┌────────────────┬───────────────┬─────────────────┬────────┐
	 *     │ Length of rest │ Rest of key   │ Length of value │ Value  │
	 *     │ of key (bytes) │               │ (bytes)         │        │
	 *     ├────────────────┼───────────────┼─────────────────┼────────┤
	 *     │ 0x08           │ nterDot;      │ 0x02            │ ·      │
	 *     └────────────────┴───────────────┴─────────────────┴────────┘
	 *
	 * In this example, the key `CenterDot;` has a group key `Ce`, leaving
	 * eight bytes for the rest of the key, `nterDot;`, and two bytes for
	 * the transformed value `·` (or U+B7 or "\xC2\xB7").
	 *
	 * Example:
	 *
	 *    // Stores array( 'CenterDot;' => '·', 'Cedilla;' => '¸' ).
	 *    $groups      = "Ce\x00";
	 *    $large_words = array( "\x08nterDot;\x02·\x06dilla;\x02¸" )
	 *
	 * The prefixes appear in the `$groups` string, each followed by a null
	 * byte. This makes for quick lookup of where in the group string the key
	 * is found, and then a simple division converts that offset into the index
	 * in the `$large_words` array where the group string is to be found.
	 *
	 * This lookup data structure is designed to optimize cache locality and
	 * minimize indirect memory reads when matching strings in the set.
	 *
	 * @since 6.6.0
	 *
	 * @var array
	 */
	private $large_words = array();

	/**
	 * Stores the group keys for sequential string lookup.
	 *
	 * The offset into this string where the group key appears corresponds with the index
	 * into the group array where the rest of the group string appears. This is an optimization
	 * to improve cache locality while searching and minimize indirect memory accesses.
	 *
	 * @since 6.6.0
	 *
	 * @var string
	 */
	private $groups = '';

	/**
	 * Stores an optimized row of small words, where every entry is
	 * `$this->key_size + 1` bytes long and zero-extended.
	 *
	 * This packing allows for direct lookup of a short word followed
	 * by the null byte, if extended to `$this->key_size + 1`.
	 *
	 * Example:
	 *
	 *     // Stores array( 'GT', 'LT', 'gt', 'lt' ).
	 *     "GT\x00LT\x00gt\x00lt\x00"
	 *
	 * @since 6.6.0
	 *
	 * @var string
	 */
	private $small_words = '';

	/**
	 * Replacements for the small words, in the same order they appear.
	 *
	 * With the position of a small word it's possible to index the translation
	 * directly, as its position in the `$small_words` string corresponds to
	 * the index of the replacement in the `$small_mapping` array.
	 *
	 * Example:
	 *
	 *     array( '>', '<', '>', '<' )
	 *
	 * @since 6.6.0
	 *
	 * @var string[]
	 */
	private $small_mappings = array();

	/**
	 * Create a token map using an associative array of key/value pairs as the input.
	 *
	 * Example:
	 *
	 *     $smilies = WP_Token_Map::from_array( array(
	 *          '8O' => '😯',
	 *          ':(' => '🙁',
	 *          ':)' => '🙂',
	 *          ':?' => '😕',
	 *       ) );
	 *
	 * @since 6.6.0
	 *
	 * @param array $mappings   The keys transform into the values, both are strings.
	 * @param int   $key_length Determines the group key length. Leave at the default value
	 *                          of 2 unless there's an empirical reason to change it.
	 *
	 * @return WP_Token_Map|null Token map, unless unable to create it.
	 */
	public static function from_array( array $mappings, int $key_length = 2 ): ?WP_Token_Map {
		$map             = new WP_Token_Map();
		$map->key_length = $key_length;

		// Start by grouping words.

		$groups = array();
		$shorts = array();
		foreach ( $mappings as $word => $mapping ) {
			if (
				self::MAX_LENGTH <= strlen( $word ) ||
				self::MAX_LENGTH <= strlen( $mapping )
			) {
				_doing_it_wrong(
					__METHOD__,
					sprintf(
						/* translators: 1: maximum byte length (a count) */
						__( 'Token Map tokens and substitutions must all be shorter than %1$d bytes.' ),
						self::MAX_LENGTH
					),
					'6.6.0'
				);
				return null;
			}

			$length = strlen( $word );

			if ( $key_length >= $length ) {
				$shorts[] = $word;
			} else {
				$group = substr( $word, 0, $key_length );

				if ( ! isset( $groups[ $group ] ) ) {
					$groups[ $group ] = array();
				}

				$groups[ $group ][] = array( substr( $word, $key_length ), $mapping );
			}
		}

		/*
		 * Sort the words to ensure that no smaller substring of a match masks the full match.
		 * For example, `Cap` should not match before `CapitalDifferentialD`.
		 */
		usort( $shorts, 'WP_Token_Map::longest_first_then_alphabetical' );
		foreach ( $groups as $group_key => $group ) {
			usort(
				$groups[ $group_key ],
				static function ( array $a, array $b ): int {
					return self::longest_first_then_alphabetical( $a[0], $b[0] );
				}
			);
		}

		// Finally construct the optimized lookups.

		foreach ( $shorts as $word ) {
			$map->small_words     .= str_pad( $word, $key_length + 1, "\x00", STR_PAD_RIGHT );
			$map->small_mappings[] = $mappings[ $word ];
		}

		$group_keys = array_keys( $groups );
		sort( $group_keys );

		foreach ( $group_keys as $group ) {
			$map->groups .= "{$group}\x00";

			$group_string = '';

			foreach ( $groups[ $group ] as $group_word ) {
				list( $word, $mapping ) = $group_word;

				$word_length    = pack( 'C', strlen( $word ) );
				$mapping_length = pack( 'C', strlen( $mapping ) );
				$group_string  .= "{$word_length}{$word}{$mapping_length}{$mapping}";
			}

			$map->large_words[] = $group_string;
		}

		return $map;
	}

	/**
	 * Creates a token map from a pre-computed table.
	 * This skips the initialization cost of generating the table.
	 *
	 * This function should only be used to load data created with
	 * WP_Token_Map::precomputed_php_source_tag().
	 *
	 * @since 6.6.0
	 *
	 * @param array $state {
	 *     Stores pre-computed state for directly loading into a Token Map.
	 *
	 *     @type string $storage_version Which version of the code produced this state.
	 *     @type int    $key_length      Group key length.
	 *     @type string $groups          Group lookup index.
	 *     @type array  $large_words     Large word groups and packed strings.
	 *     @type string $small_words     Small words packed string.
	 *     @type array  $small_mappings  Small word mappings.
	 * }
	 *
	 * @return WP_Token_Map Map with precomputed data loaded.
	 */
	public static function from_precomputed_table( $state ): ?WP_Token_Map {
		$has_necessary_state = isset(
			$state['storage_version'],
			$state['key_length'],
			$state['groups'],
			$state['large_words'],
			$state['small_words'],
			$state['small_mappings']
		);

		if ( ! $has_necessary_state ) {
			_doing_it_wrong(
				__METHOD__,
				__( 'Missing required inputs to pre-computed WP_Token_Map.' ),
				'6.6.0'
			);
			return null;
		}

		if ( self::STORAGE_VERSION !== $state['storage_version'] ) {
			_doing_it_wrong(
				__METHOD__,
				/* translators: 1: version string, 2: version string. */
				sprintf( __( 'Loaded version \'%1$s\' incompatible with expected version \'%2$s\'.' ), $state['storage_version'], self::STORAGE_VERSION ),
				'6.6.0'
			);
			return null;
		}

		$map = new WP_Token_Map();

		$map->key_length     = $state['key_length'];
		$map->groups         = $state['groups'];
		$map->large_words    = $state['large_words'];
		$map->small_words    = $state['small_words'];
		$map->small_mappings = $state['small_mappings'];

		return $map;
	}

	/**
	 * Indicates if a given word is a lookup key in the map.
	 *
	 * Example:
	 *
	 *     true  === $smilies->contains( ':)' );
	 *     false === $smilies->contains( 'simile' );
	 *
	 * @since 6.6.0
	 *
	 * @param string $word             Determine if this word is a lookup key in the map.
	 * @param string $case_sensitivity Optional. Pass 'ascii-case-insensitive' to ignore ASCII case when matching. Default 'case-sensitive'.
	 * @return bool Whether there's an entry for the given word in the map.
	 */
	public function contains( string $word, string $case_sensitivity = 'case-sensitive' ): bool {
		if ( str_contains( $word, "\x00" ) ) {
			return false;
		}

		$ignore_case = 'ascii-case-insensitive' === $case_sensitivity;

		if ( $this->key_length >= strlen( $word ) ) {
			if ( 0 === strlen( $this->small_words ) ) {
				return false;
			}

			$term = str_pad( $word, $this->key_length + 1, "\x00", STR_PAD_RIGHT );
			if ( ! $ignore_case ) {
				return false !== strpos( $this->small_words, $term );
			}

			$small_length  = strlen( $this->small_words );
			$record_length = $this->key_length + 1;
			for ( $at = 0; $at < $small_length; $at += $record_length ) {
				if ( self::matches_at( $this->small_words, $term, $at, $record_length, $ignore_case ) ) {
					return true;
				}
			}

			return false;
		}

		$group_key     = substr( $word, 0, $this->key_length );
		$group_indexes = $this->find_group_indexes( $group_key, $ignore_case );
		if ( empty( $group_indexes ) ) {
			return false;
		}

		$slug   = substr( $word, $this->key_length );
		$length = strlen( $slug );

		foreach ( $group_indexes as $group_index ) {
			$group        = $this->large_words[ $group_index ];
			$group_length = strlen( $group );
			$at           = 0;

			while ( $at < $group_length ) {
				$token_length   = unpack( 'C', $group[ $at++ ] )[1];
				$token_at       = $at;
				$at            += $token_length;
				$mapping_length = unpack( 'C', $group[ $at++ ] )[1];
				$mapping_at     = $at;

				if ( $token_length === $length && self::matches_at( $group, $slug, $token_at, $token_length, $ignore_case ) ) {
					return true;
				}

				$at = $mapping_at + $mapping_length;
			}
		}

		return false;
	}

	/**
	 * If the text starting at a given offset is a lookup key in the map,
	 * return the corresponding transformation from the map, else `false`.
	 *
	 * This function returns the translated string, but accepts an optional
	 * parameter `$matched_token_byte_length`, which communicates how many
	 * bytes long the lookup key was, if it found one. This can be used to
	 * advance a cursor in calling code if a lookup key was found.
	 *
	 * Example:
	 *
	 *     false === $smilies->read_token( 'Not sure :?.', 0, $token_byte_length );
	 *     '😕'  === $smilies->read_token( 'Not sure :?.', 9, $token_byte_length );
	 *     2     === $token_byte_length;
	 *
	 * Example:
	 *
	 *     while ( $at < strlen( $input ) ) {
	 *         $next_at = strpos( $input, ':', $at );
	 *         if ( false === $next_at ) {
	 *             break;
	 *         }
	 *
	 *         $smily = $smilies->read_token( $input, $next_at, $token_byte_length );
	 *         if ( false === $next_at ) {
	 *             ++$at;
	 *             continue;
	 *         }
	 *
	 *         $prefix  = substr( $input, $at, $next_at - $at );
	 *         $at     += $token_byte_length;
	 *         $output .= "{$prefix}{$smily}";
	 *     }
	 *
	 * @since 6.6.0
	 *
	 * @param string   $text                       String in which to search for a lookup key.
	 * @param int      $offset                     Optional. How many bytes into the string where the lookup key ought to start. Default 0.
	 * @param int|null &$matched_token_byte_length Optional. Holds byte-length of found token matched, otherwise not set. Default null.
	 * @param string   $case_sensitivity           Optional. Pass 'ascii-case-insensitive' to ignore ASCII case when matching. Default 'case-sensitive'.
	 * @return string|null Mapped value of lookup key if found, otherwise `null`.
	 */
	public function read_token( string $text, int $offset = 0, &$matched_token_byte_length = null, $case_sensitivity = 'case-sensitive' ): ?string {
		$ignore_case = 'ascii-case-insensitive' === $case_sensitivity;
		$text_length = strlen( $text );

		// Search for a long word first, if the text is long enough, and if that fails, a short one.
		if ( $text_length - $offset > $this->key_length ) {
			/*
			 * Keys cannot contain null bytes, which is taken care of for the full words,
			 * but here it’s required to reject group keys with null bytes so that the
			 * lookup doesn’t get off track when scanning the group string.
			 */
			if ( strcspn( $text, "\x00", $offset, $this->key_length ) < $this->key_length ) {
				return strlen( $this->small_words ) > 0
					? $this->read_small_token( $text, $offset, $matched_token_byte_length, $case_sensitivity )
					: null;
			}

			$group_key     = substr( $text, $offset, $this->key_length );
			$group_indexes = $this->find_group_indexes( $group_key, $ignore_case );
			if ( empty( $group_indexes ) ) {
				// Perhaps a short word then.
				return strlen( $this->small_words ) > 0
					? $this->read_small_token( $text, $offset, $matched_token_byte_length, $case_sensitivity )
					: null;
			}

			if ( ! $ignore_case ) {
				$group        = $this->large_words[ $group_indexes[0] ];
				$group_length = strlen( $group );
				$at           = 0;
				while ( $at < $group_length ) {
					$token_length   = unpack( 'C', $group[ $at++ ] )[1];
					$token          = substr( $group, $at, $token_length );
					$at            += $token_length;
					$mapping_length = unpack( 'C', $group[ $at++ ] )[1];
					$mapping_at     = $at;

					if ( 0 === substr_compare( $text, $token, $offset + $this->key_length, $token_length ) ) {
						$matched_token_byte_length = $this->key_length + $token_length;
						return substr( $group, $mapping_at, $mapping_length );
					}

					$at = $mapping_at + $mapping_length;
				}

				return strlen( $this->small_words ) > 0
					? $this->read_small_token( $text, $offset, $matched_token_byte_length, $case_sensitivity )
					: null;
			}

			$best_match_length = null;
			$best_mapping      = null;
			foreach ( $group_indexes as $group_index ) {
				$group        = $this->large_words[ $group_index ];
				$group_length = strlen( $group );
				$at           = 0;
				while ( $at < $group_length ) {
					$token_length   = unpack( 'C', $group[ $at++ ] )[1];
					$token          = substr( $group, $at, $token_length );
					$at            += $token_length;
					$mapping_length = unpack( 'C', $group[ $at++ ] )[1];
					$mapping_at     = $at;

					if ( self::matches_at( $text, $token, $offset + $this->key_length, $token_length, $ignore_case ) ) {
						$match_length = $this->key_length + $token_length;
						if ( null === $best_match_length || $match_length > $best_match_length ) {
							$best_match_length = $match_length;
							$best_mapping      = substr( $group, $mapping_at, $mapping_length );
						}
					}

					$at = $mapping_at + $mapping_length;
				}
			}

			if ( null !== $best_match_length ) {
				$matched_token_byte_length = $best_match_length;
				return $best_mapping;
			}
		}

		// Perhaps a short word then.
		return strlen( $this->small_words ) > 0
			? $this->read_small_token( $text, $offset, $matched_token_byte_length, $case_sensitivity )
			: null;
	}

	/**
	 * Finds a match for a short word at the index.
	 *
	 * @since 6.6.0
	 *
	 * @param string   $text                       String in which to search for a lookup key.
	 * @param int      $offset                     Optional. How many bytes into the string where the lookup key ought to start. Default 0.
	 * @param int|null &$matched_token_byte_length Optional. Holds byte-length of found lookup key if matched, otherwise not set. Default null.
	 * @param string   $case_sensitivity           Optional. Pass 'ascii-case-insensitive' to ignore ASCII case when matching. Default 'case-sensitive'.
	 * @return string|null Mapped value of lookup key if found, otherwise `null`.
	 */
	private function read_small_token( string $text, int $offset = 0, &$matched_token_byte_length = null, $case_sensitivity = 'case-sensitive' ): ?string {
		$ignore_case  = 'ascii-case-insensitive' === $case_sensitivity;
		$small_length = strlen( $this->small_words );
		$search_text  = substr( $text, $offset, $this->key_length );
		if ( '' === $search_text ) {
			return null;
		}

		if ( $ignore_case ) {
			$search_text = self::ascii_lowercase( $search_text );
		}
		$starting_char = $search_text[0];

		$at = 0;
		while ( $at < $small_length ) {
			$stored_starting_char = $ignore_case
				? self::ascii_lowercase( $this->small_words[ $at ] )
				: $this->small_words[ $at ];

			if (
				$starting_char !== $stored_starting_char
			) {
				$at += $this->key_length + 1;
				continue;
			}

			for ( $adjust = 1; $adjust < $this->key_length; $adjust++ ) {
				if ( "\x00" === $this->small_words[ $at + $adjust ] ) {
					$matched_token_byte_length = $adjust;
					return $this->small_mappings[ $at / ( $this->key_length + 1 ) ];
				}

				if ( ! isset( $search_text[ $adjust ] ) ) {
					$at += $this->key_length + 1;
					continue 2;
				}

				$stored_char = $ignore_case
					? self::ascii_lowercase( $this->small_words[ $at + $adjust ] )
					: $this->small_words[ $at + $adjust ];

				if (
					$search_text[ $adjust ] !== $stored_char
				) {
					$at += $this->key_length + 1;
					continue 2;
				}
			}

			$matched_token_byte_length = $adjust;
			return $this->small_mappings[ $at / ( $this->key_length + 1 ) ];
		}

		return null;
	}

	/**
	 * Exports the token map into an associate array of key/value pairs.
	 *
	 * Example:
	 *
	 *     $smilies->to_array() === array(
	 *         '8O' => '😯',
	 *         ':(' => '🙁',
	 *         ':)' => '🙂',
	 *         ':?' => '😕',
	 *     );
	 *
	 * @return array The lookup key/substitution values as an associate array.
	 */
	public function to_array(): array {
		$tokens = array();

		$at            = 0;
		$small_mapping = 0;
		$small_length  = strlen( $this->small_words );
		while ( $at < $small_length ) {
			$key            = rtrim( substr( $this->small_words, $at, $this->key_length + 1 ), "\x00" );
			$value          = $this->small_mappings[ $small_mapping++ ];
			$tokens[ $key ] = $value;

			$at += $this->key_length + 1;
		}

		foreach ( $this->large_words as $index => $group ) {
			$prefix       = substr( $this->groups, $index * ( $this->key_length + 1 ), $this->key_length );
			$group_length = strlen( $group );
			$at           = 0;
			while ( $at < $group_length ) {
				$length = unpack( 'C', $group[ $at++ ] )[1];
				$key    = $prefix . substr( $group, $at, $length );

				$at    += $length;
				$length = unpack( 'C', $group[ $at++ ] )[1];
				$value  = substr( $group, $at, $length );

				$tokens[ $key ] = $value;
				$at            += $length;
			}
		}

		return $tokens;
	}

	/**
	 * Export the token map for quick loading in PHP source code.
	 *
	 * This function has a specific purpose, to make loading of static token maps fast.
	 * It's used to ensure that the HTML character reference lookups add a minimal cost
	 * to initializing the PHP process.
	 *
	 * Example:
	 *
	 *     echo $smilies->precomputed_php_source_table();
	 *
	 *     // Output.
	 *     WP_Token_Map::from_precomputed_table(
	 *         array(
	 *             "storage_version" => "6.6.0",
	 *             "key_length" => 2,
	 *             "groups" => "",
	 *             "long_words" => array(),
	 *             "small_words" => "8O\x00:)\x00:(\x00:?\x00",
	 *             "small_mappings" => array( "😯", "🙂", "🙁", "😕" )
	 *         )
	 *     );
	 *
	 * @since 6.6.0
	 *
	 * @param string $indent Optional. Use this string for indentation, or rely on the default horizontal tab character. Default "\t".
	 * @return string Value which can be pasted into a PHP source file for quick loading of table.
	 */
	public function precomputed_php_source_table( string $indent = "\t" ): string {
		$i1 = $indent;
		$i2 = $i1 . $indent;
		$i3 = $i2 . $indent;

		$class_version = self::STORAGE_VERSION;

		$output  = self::class . "::from_precomputed_table(\n";
		$output .= "{$i1}array(\n";
		$output .= "{$i2}\"storage_version\" => \"{$class_version}\",\n";
		$output .= "{$i2}\"key_length\" => {$this->key_length},\n";

		$group_line = self::escape_precomputed_php_string( $this->groups );
		$output    .= "{$i2}\"groups\" => \"{$group_line}\",\n";

		$output .= "{$i2}\"large_words\" => array(\n";

		$prefixes = explode( "\x00", $this->groups );
		foreach ( $prefixes as $index => $prefix ) {
			if ( '' === $prefix ) {
				break;
			}
			$group        = $this->large_words[ $index ];
			$group_length = strlen( $group );
			$comment_line = "{$i3}//";
			$group_data   = '';
			$at           = 0;
			while ( $at < $group_length ) {
				$token_length   = unpack( 'C', $group[ $at++ ] )[1];
				$token          = substr( $group, $at, $token_length );
				$at            += $token_length;
				$mapping_length = unpack( 'C', $group[ $at++ ] )[1];
				$mapping        = substr( $group, $at, $mapping_length );
				$at            += $mapping_length;

				$group_data   .= pack( 'C', $token_length ) . $token . pack( 'C', $mapping_length ) . $mapping;
				$comment_line .= ' ' . self::escape_precomputed_php_comment( "{$prefix}{$token}" ) . '[' . self::escape_precomputed_php_comment( $mapping ) . ']';
			}
			$comment_line .= ".\n";
			$data_line     = "{$i3}\"" . self::escape_precomputed_php_string( $group_data ) . "\",\n";

			$output .= $comment_line;
			$output .= $data_line;
		}

		$output .= "{$i2}),\n";

		$small_words  = array();
		$small_length = strlen( $this->small_words );
		$at           = 0;
		while ( $at < $small_length ) {
			$small_words[] = substr( $this->small_words, $at, $this->key_length + 1 );
			$at           += $this->key_length + 1;
		}

		$small_text = self::escape_precomputed_php_string( implode( '', $small_words ) );
		$output    .= "{$i2}\"small_words\" => \"{$small_text}\",\n";

		$output .= "{$i2}\"small_mappings\" => array(\n";
		foreach ( $this->small_mappings as $mapping ) {
			$output .= "{$i3}\"" . self::escape_precomputed_php_string( $mapping ) . "\",\n";
		}
		$output .= "{$i2})\n";
		$output .= "{$i1})\n";
		$output .= ')';

		return $output;
	}

	/**
	 * Compares two strings, returning the longest, or whichever
	 * is first alphabetically if they are the same length.
	 *
	 * This is an important sort when building the token map because
	 * it should not form a match on a substring of a longer potential
	 * match. For example, it should not detect `Cap` when matching
	 * against the string `CapitalDifferentialD`.
	 *
	 * @since 6.6.0
	 *
	 * @param string $a First string to compare.
	 * @param string $b Second string to compare.
	 * @return int -1 or lower if `$a` is less than `$b`; 1 or greater if `$a` is greater than `$b`, and 0 if they are equal.
	 */
	private static function longest_first_then_alphabetical( string $a, string $b ): int {
		if ( $a === $b ) {
			return 0;
		}

		$length_a = strlen( $a );
		$length_b = strlen( $b );

		// Longer strings are less-than for comparison's sake.
		if ( $length_a !== $length_b ) {
			return $length_b - $length_a;
		}

		return strcmp( $a, $b );
	}

	/**
	 * Finds group indexes that match a lookup key.
	 *
	 * @since 6.6.0
	 *
	 * @param string $group_key   Group key to find.
	 * @param bool   $ignore_case Whether to fold ASCII case while searching.
	 * @return int[] Matching group indexes.
	 */
	private function find_group_indexes( string $group_key, bool $ignore_case ): array {
		if ( ! $ignore_case ) {
			$group_at = strpos( $this->groups, $group_key );

			return false === $group_at
				? array()
				: array( $group_at / ( $this->key_length + 1 ) );
		}

		$group_indexes = array();
		$record_length = $this->key_length + 1;
		$groups_length = strlen( $this->groups );
		$group_index   = 0;

		for ( $at = 0; $at < $groups_length; $at += $record_length ) {
			if ( self::matches_at( $this->groups, $group_key, $at, $this->key_length, $ignore_case ) ) {
				$group_indexes[] = $group_index;
			}

			++$group_index;
		}

		return $group_indexes;
	}

	/**
	 * Checks whether a substring matches at a given offset.
	 *
	 * @since 6.6.0
	 *
	 * @param string $haystack    String to search within.
	 * @param string $needle      String to match.
	 * @param int    $offset      Offset into the haystack.
	 * @param int    $length      Number of bytes to compare.
	 * @param bool   $ignore_case Whether to fold ASCII case while matching.
	 * @return bool Whether the substring matched.
	 */
	private static function matches_at( string $haystack, string $needle, int $offset, int $length, bool $ignore_case ): bool {
		$candidate = substr( $haystack, $offset, $length );
		if ( strlen( $candidate ) !== $length ) {
			return false;
		}

		if ( ! $ignore_case ) {
			return $candidate === $needle;
		}

		return self::ascii_lowercase( $candidate ) === self::ascii_lowercase( $needle );
	}

	/**
	 * Lowercases ASCII bytes only.
	 *
	 * @since 6.6.0
	 *
	 * @param string $text Text to lowercase.
	 * @return string Text with only ASCII uppercase bytes folded to lowercase.
	 */
	private static function ascii_lowercase( string $text ): string {
		return strtr( $text, 'ABCDEFGHIJKLMNOPQRSTUVWXYZ', 'abcdefghijklmnopqrstuvwxyz' );
	}

	/**
	 * Escapes text for use inside a double-quoted PHP string literal.
	 *
	 * @since 6.6.0
	 *
	 * @param string $text Text to escape.
	 * @return string Escaped string literal body.
	 */
	private static function escape_precomputed_php_string( string $text ): string {
		$escaped = '';
		$length  = strlen( $text );

		for ( $i = 0; $i < $length; $i++ ) {
			$byte = ord( $text[ $i ] );
			switch ( $text[ $i ] ) {
				case '"':
					$escaped .= '\\"';
					break;

				case '\\':
					$escaped .= '\\\\';
					break;

				case '$':
					$escaped .= '\\$';
					break;

				default:
					$escaped .= ( $byte < 0x20 || $byte >= 0x7f )
						? sprintf( '\\x%02x', $byte )
						: $text[ $i ];
			}
		}

		return $escaped;
	}

	/**
	 * Escapes text for use inside generated PHP comments.
	 *
	 * @since 6.6.0
	 *
	 * @param string $text Text to escape.
	 * @return string Escaped comment text.
	 */
	private static function escape_precomputed_php_comment( string $text ): string {
		$escaped = '';
		$length  = strlen( $text );

		for ( $i = 0; $i < $length; $i++ ) {
			$byte = ord( $text[ $i ] );
			$char = $text[ $i ];

			$escaped .= ( $byte < 0x20 || $byte >= 0x7f || '?' === $char || '\\' === $char )
				? sprintf( '\\x%02x', $byte )
				: $char;
		}

		return $escaped;
	}
}
