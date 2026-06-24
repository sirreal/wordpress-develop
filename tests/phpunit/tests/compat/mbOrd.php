<?php

/**
 * @group compat
 *
 * @covers ::mb_ord
 */
class Tests_Compat_mbOrd extends WP_UnitTestCase {
	/**
	 * Ensures that the mb_ord() polyfill matches the behavior of mb_ord()
	 * for the supported UTF-8 encoding.
	 *
	 * @ticket 65342
	 */
	public function test_mb_ord_polyfill_matches_spec() {
		for ( $code_point = 0; $code_point <= 0x10FFFF; $code_point++ ) {
			/*
			 * Some code points cannot be constructed in UTF-8 because they
			 * are invalid; notably the surrogate halves. While they could be
			 * manually constructed here using the direct UTF-8 encoder without
			 * its constraints, it’s sufficient to test the positive cases here
			 * and spot-check an unpaired and incorrectly-converted surrogate
			 * half below.
			 */
			$char = mb_chr( $code_point );

			if ( false !== $char && $code_point !== _mb_ord( $char ) ) {
				$hex_char = strtoupper( str_pad( dechex( $code_point ), 4, '0', STR_PAD_LEFT ) );
				$this->fail( "Failed to properly decode U+{$hex_char} from the string." );
			}
		}

		$this->assertTrue( true, 'All valid code points were decoded properly.' );
		$this->assertFalse( _mb_ord( '' ), 'Should have failed on empty string.' );
		$this->assertFalse( _mb_ord( 'hi', 'latin1' ), 'Should have rejected non-UTF-8 encoding.' );
		$this->assertFalse( _mb_ord( 'hi', 'utf8' ), 'Should have rejected non-UTF-8 encoding.' );
		$this->assertSame( ord( 'A' ), _mb_ord( 'A', 'UTF-8' ), 'Should have accepted UTF-8 encoding.' );
		$this->assertFalse( _mb_ord( "\xC0" ), 'Should have rejected invalid UTF-8 code point.' );
		$this->assertFalse( _mb_ord( substr( "\xED\xA0\x80", 0, 2 ) ), 'Should have rejected unpaired surrogate half.' );
	}
}
