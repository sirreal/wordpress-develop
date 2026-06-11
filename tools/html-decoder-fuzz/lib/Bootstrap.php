<?php
namespace {
	if ( ! function_exists( '__' ) ) {
		function __( $text ) {
			return $text;
		}
	}

	if ( ! function_exists( '_doing_it_wrong' ) ) {
		function _doing_it_wrong( $function_name, $message, $version ) {
			unset( $function_name, $message, $version );
		}
	}
}

namespace HtmlDecoderFuzz {

/**
 * Loads only the WordPress code needed by WP_HTML_Decoder.
 */
class Bootstrap {
	public static function repo_root(): string {
		return dirname( __DIR__, 3 );
	}

	public static function load_targets(): void {
		if ( class_exists( \WP_HTML_Decoder::class, false ) ) {
			return;
		}

		$root = self::repo_root();
		require_once $root . '/src/wp-includes/class-wp-token-map.php';
		require_once $root . '/src/wp-includes/html-api/html5-named-character-references.php';
		require_once $root . '/src/wp-includes/html-api/class-wp-html-decoder.php';

		global $html5_named_character_references;
		if ( ! $html5_named_character_references instanceof \WP_Token_Map ) {
			throw new \RuntimeException( 'Failed to load the HTML5 named character reference map.' );
		}
	}

	/**
	 * Extracts the generated HTML5 named-reference keys from WP_Token_Map.
	 *
	 * @return string[] Reference names without the leading ampersand. Some end
	 *                  in semicolon, and legacy entries also appear without it.
	 */
	public static function named_reference_names(): array {
		static $names = null;
		if ( null !== $names ) {
			return $names;
		}

		$structure = self::named_reference_structure();
		$names     = array_merge( $structure['large_names'], $structure['small_names'] );
		self::sort_names( $names );

		return $names;
	}

	/**
	 * Extracts lookup-layout details from the generated HTML5 named-reference map.
	 *
	 * @return array{
	 *     key_length: int,
	 *     group_prefixes: string[],
	 *     large_names: string[],
	 *     large_names_by_prefix: array<string, string[]>,
	 *     small_names: string[]
	 * }
	 */
	public static function named_reference_structure(): array {
		static $structure = null;
		if ( null !== $structure ) {
			return $structure;
		}

		self::load_targets();

		global $html5_named_character_references;
		$map = $html5_named_character_references;

		$reflection = new \ReflectionObject( $map );
		$get        = static function ( string $property ) use ( $reflection, $map ) {
			$ref = $reflection->getProperty( $property );
			$ref->setAccessible( true );
			return $ref->getValue( $map );
		};

		$key_length            = (int) $get( 'key_length' );
		$groups                = (string) $get( 'groups' );
		$large_words           = (array) $get( 'large_words' );
		$small_words           = (string) $get( 'small_words' );
		$large_names_by_key    = array();
		$large_names_by_prefix = array();
		$small_names_by_key    = array();
		$group_stride          = $key_length + 1;
		$groups_length         = strlen( $groups );

		for ( $group_at = 0, $group_index = 0; $group_at + $key_length <= $groups_length; $group_at += $group_stride, ++$group_index ) {
			$prefix = substr( $groups, $group_at, $key_length );
			if ( '' === $prefix || ! isset( $large_words[ $group_index ] ) ) {
				continue;
			}

			$row    = $large_words[ $group_index ];
			$row_at = 0;
			while ( $row_at < strlen( $row ) ) {
				$token_length = unpack( 'C', $row[ $row_at++ ] )[1];
				$token        = substr( $row, $row_at, $token_length );
				$row_at      += $token_length;

				$mapping_length = unpack( 'C', $row[ $row_at++ ] )[1];
				$row_at        += $mapping_length;

				$name = $prefix . $token;
				$large_names_by_key[ $name ] = true;
				$large_names_by_prefix[ $prefix ][ $name ] = true;
			}
		}

		for ( $at = 0; $at < strlen( $small_words ); $at += $group_stride ) {
			$name = rtrim( substr( $small_words, $at, $group_stride ), "\x00" );
			if ( '' !== $name ) {
				$small_names_by_key[ $name ] = true;
			}
		}

		$group_prefixes = array_keys( $large_names_by_prefix );
		sort( $group_prefixes, SORT_STRING );

		$large_names = array_keys( $large_names_by_key );
		$small_names = array_keys( $small_names_by_key );
		self::sort_names( $large_names );
		self::sort_names( $small_names );
		foreach ( $large_names_by_prefix as $prefix => $prefix_names_by_key ) {
			$prefix_names = array_keys( $prefix_names_by_key );
			self::sort_names( $prefix_names );
			$large_names_by_prefix[ $prefix ] = $prefix_names;
		}
		ksort( $large_names_by_prefix, SORT_STRING );

		$structure = array(
			'key_length'             => $key_length,
			'group_prefixes'         => $group_prefixes,
			'large_names'            => $large_names,
			'large_names_by_prefix'  => $large_names_by_prefix,
			'small_names'            => $small_names,
		);

		return $structure;
	}

	/**
	 * @param string[] $names
	 */
	private static function sort_names( array &$names ): void {
		usort(
			$names,
			static function ( string $a, string $b ): int {
				return strlen( $b ) <=> strlen( $a ) ?: strcmp( $a, $b );
			}
		);
	}
}
}
