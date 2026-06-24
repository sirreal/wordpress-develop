<?php

/**
 * @group compat
 *
 * @covers ::mb_chr
 */
class Tests_Compat_mbChr extends WP_UnitTestCase {
	/**
	 * Ensures that the mb_chr() polyfill matches the behavior of mb_chr()
	 * for the supported UTF-8 encoding.
	 *
	 * @ticket 65342
	 */
	public function test_mb_chr_polyfill_matches_spec() {
		for ( $code_point = 0; $code_point <= 0x10FFFF; $code_point++ ) {
			if ( mb_chr( $code_point ) !== _mb_chr( $code_point ) ) {
				$hex_char = strtoupper( str_pad( dechex( $code_point ), 4, '0', STR_PAD_LEFT ) );
				$this->fail( "Failed to properly encode U+{$hex_char}." );
			}
		}

		$this->assertTrue( true, 'All valid code points were encoded properly.' );
		$this->assertFalse( _mb_chr( ord( 'A' ), 'latin1' ), 'Should have rejected non-UTF-8 encoding.' );
		$this->assertFalse( _mb_ord( ord( 'A' ), 'utf8' ), 'Should have rejected non-UTF-8 encoding.' );
		$this->assertSame( 'A', _mb_chr( ord( 'A' ), 'UTF-8' ), 'Should have accepted UTF-8 encoding.' );
	}
}
