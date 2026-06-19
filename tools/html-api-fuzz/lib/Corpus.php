<?php
namespace HtmlApiFuzz;

/**
 * Seed corpus drawn from the html5lib-tests tree-construction suite. These
 * inputs encode decades of parser edge cases; mutating them explores
 * neighborhoods that the structural generator's grammar never reaches.
 */
class Corpus {
	private static $entries = null;

	public static function default_directory(): string {
		return repo_root() . '/tests/phpunit/data/html5lib-tests/tree-construction';
	}

	/**
	 * Returns the corpus entries: every #data section from every .dat file,
	 * sorted deterministically. Cached per process.
	 */
	public static function entries( ?string $directory = null ): array {
		if ( null === $directory && null !== self::$entries ) {
			return self::$entries;
		}

		$dir     = $directory ?? self::default_directory();
		$entries = array();
		$files = is_dir( $dir ) ? glob( $dir . '/*.dat' ) : false;
		$files = false === $files ? array() : $files;
		sort( $files );
		foreach ( $files as $file ) {
			$contents = file_get_contents( $file );
			if ( false === $contents ) {
				continue;
			}
			foreach ( self::parse_dat_data_sections( $contents ) as $data ) {
				$entries[] = array(
					'file' => basename( $file ),
					'data' => $data,
				);
			}
		}

		if ( null === $directory ) {
			self::$entries = $entries;
		}
		return $entries;
	}

	/**
	 * Extracts #data sections from html5lib .dat content. A section runs from
	 * the line after `#data` to the line before the next `#` directive, with
	 * the trailing newline removed.
	 */
	private static function parse_dat_data_sections( string $contents ): array {
		$sections = array();
		$lines    = explode( "\n", $contents );
		$current  = null;
		foreach ( $lines as $line ) {
			if ( '#data' === $line ) {
				$current = array();
				continue;
			}
			if ( null !== $current ) {
				if ( '' !== $line && '#' === $line[0] ) {
					$sections[] = implode( "\n", $current );
					$current    = null;
					continue;
				}
				$current[] = $line;
			}
		}
		if ( null !== $current && array() !== $current ) {
			$sections[] = implode( "\n", $current );
		}

		return $sections;
	}
}
