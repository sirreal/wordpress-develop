<?php
namespace EncodingFuzz;

/**
 * Deterministic fixed corpora that complement, but do not perturb, the
 * pseudo-random generator.
 */
class Corpus {
	/**
	 * Required UTF-8 boundary representatives from the handoff, plus adjacent
	 * comparison bytes needed to hit the real Table 3-7 cut points.
	 */
	private const BOUNDARY_BYTES = array(
		0x00,
		0x7E,
		0x7F,
		0x80,
		0x8F,
		0x90,
		0x9F,
		0xA0,
		0xBF,
		0xC0,
		0xC1,
		0xC2,
		0xDF,
		0xE0,
		0xE1,
		0xEC,
		0xED,
		0xEE,
		0xEF,
		0xF0,
		0xF1,
		0xF3,
		0xF4,
		0xF5,
		0xFE,
		0xFF,
	);

	/**
	 * The exact lead byte class representatives named in the handoff.
	 */
	private const LEAD_CLASS_BYTES = array(
		0x7F,
		0x80,
		0xBF,
		0xC0,
		0xC1,
		0xC2,
		0xDF,
		0xE0,
		0xE1,
		0xEC,
		0xED,
		0xEE,
		0xEF,
		0xF0,
		0xF1,
		0xF3,
		0xF4,
		0xF5,
		0xFE,
		0xFF,
	);

	private const TWO_BYTE_LEADS   = array( 0xC0, 0xC1, 0xC2, 0xDF );
	private const THREE_BYTE_LEADS = array( 0xE0, 0xE1, 0xEC, 0xED, 0xEE, 0xEF );
	private const FOUR_BYTE_LEADS  = array( 0xF0, 0xF1, 0xF3, 0xF4, 0xF5 );

	/**
	 * @return array<int, array{label: string, bytes: string}>
	 */
	public static function short_boundary_cases(): array {
		$cases = array();

		self::add_lead_boundary_cases( $cases );
		self::add_adjacent_invalid_cases( $cases );
		self::add_sandwich_cases( $cases );
		self::add_truncation_cases( $cases );
		self::add_noncharacter_boundary_cases( $cases );

		return $cases;
	}

	/**
	 * @param array<int, array{label: string, bytes: string}> $cases
	 */
	private static function add_lead_boundary_cases( array &$cases ): void {
		foreach ( self::LEAD_CLASS_BYTES as $lead ) {
			self::add_case( $cases, 'lead:' . self::hex_byte( $lead ), self::bytes( $lead ) );
		}

		foreach ( self::TWO_BYTE_LEADS as $lead ) {
			foreach ( self::BOUNDARY_BYTES as $second ) {
				self::add_case(
					$cases,
					sprintf( 'two-second:%02x-%02x', $lead, $second ),
					self::bytes( $lead, $second )
				);
			}
		}

		foreach ( self::THREE_BYTE_LEADS as $lead ) {
			list( $base_second, $base_third ) = self::three_byte_baseline( $lead );
			foreach ( self::BOUNDARY_BYTES as $second ) {
				self::add_case(
					$cases,
					sprintf( 'three-second:%02x-%02x-%02x', $lead, $second, $base_third ),
					self::bytes( $lead, $second, $base_third )
				);
			}

			foreach ( self::BOUNDARY_BYTES as $third ) {
				self::add_case(
					$cases,
					sprintf( 'three-third:%02x-%02x-%02x', $lead, $base_second, $third ),
					self::bytes( $lead, $base_second, $third )
				);
			}
		}

		foreach ( self::FOUR_BYTE_LEADS as $lead ) {
			list( $base_second, $base_third, $base_fourth ) = self::four_byte_baseline( $lead );
			foreach ( self::BOUNDARY_BYTES as $second ) {
				self::add_case(
					$cases,
					sprintf( 'four-second:%02x-%02x-%02x-%02x', $lead, $second, $base_third, $base_fourth ),
					self::bytes( $lead, $second, $base_third, $base_fourth )
				);
			}

			foreach ( self::BOUNDARY_BYTES as $third ) {
				self::add_case(
					$cases,
					sprintf( 'four-third:%02x-%02x-%02x-%02x', $lead, $base_second, $third, $base_fourth ),
					self::bytes( $lead, $base_second, $third, $base_fourth )
				);
			}

			foreach ( self::BOUNDARY_BYTES as $fourth ) {
				self::add_case(
					$cases,
					sprintf( 'four-fourth:%02x-%02x-%02x-%02x', $lead, $base_second, $base_third, $fourth ),
					self::bytes( $lead, $base_second, $base_third, $fourth )
				);
			}
		}
	}

	/**
	 * @param array<int, array{label: string, bytes: string}> $cases
	 */
	private static function add_adjacent_invalid_cases( array &$cases ): void {
		$adjacent = array(
			'continuation-run'    => "\x80\xBF\x80",
			'never-valid-leads'   => "\xC0\xC1\xF5\xFE\xFF",
			'overlong-pair'       => "\xE0\x80\xE0\x9F",
			'surrogate-pair'      => "\xED\xA0\xED\xB0",
			'past-range-pair'     => "\xF4\x90\xF5\x80",
			'truncated-three'     => "\xE2\x8C\xE2\x8C",
			'truncated-four'      => "\xF1\x80\x80\xF0\x90",
			'unicode-table-3-8'   => "\xF1\x80\x80\xE1\x80\xC2",
			'bad-lead-after-cont' => "\x80\xF5\xBF\xFE",
		);

		foreach ( $adjacent as $label => $bytes ) {
			self::add_case( $cases, "adjacent-invalid:{$label}", $bytes );
		}
	}

	/**
	 * @param array<int, array{label: string, bytes: string}> $cases
	 */
	private static function add_sandwich_cases( array &$cases ): void {
		$valid_atoms = array(
			'ascii'        => 'a',
			'two-byte'     => "\xC2\x80",
			'three-byte'   => "\xE2\x9C\x8F",
			'four-byte'    => "\xF0\x90\x80\x80",
			'noncharacter' => "\xEF\xBF\xBE",
		);
		$malformed = array(
			'lone-continuation' => "\x80",
			'never-valid-c0'    => "\xC0",
			'truncated-two'     => "\xC2",
			'overlong-three'    => "\xE0\x80",
			'surrogate'         => "\xED\xA0",
			'truncated-three'   => "\xE2\x8C",
			'truncated-four'    => "\xF1\x80\x80",
			'past-range'        => "\xF4\x90",
			'never-valid-f5'    => "\xF5",
			'never-valid-ff'    => "\xFF",
		);

		foreach ( $valid_atoms as $valid_label => $valid ) {
			foreach ( $malformed as $bad_label => $bad ) {
				self::add_case( $cases, "sandwich:{$valid_label}-before-{$bad_label}", $valid . $bad );
				self::add_case( $cases, "sandwich:{$bad_label}-before-{$valid_label}", $bad . $valid );
				self::add_case( $cases, "sandwich:{$valid_label}-around-{$bad_label}", $valid . $bad . $valid );
			}
		}
	}

	/**
	 * @param array<int, array{label: string, bytes: string}> $cases
	 */
	private static function add_truncation_cases( array &$cases ): void {
		$complete = array(
			'two-min'      => "\xC2\x80",
			'two-max'      => "\xDF\xBF",
			'three-min'    => "\xE0\xA0\x80",
			'three-mid'    => "\xE1\x80\x80",
			'surrogate-hi' => "\xED\x9F\xBF",
			'nonchar'      => "\xEF\xBF\xBE",
			'four-min'     => "\xF0\x90\x80\x80",
			'four-mid'     => "\xF1\x80\x80\x80",
			'four-max'     => "\xF4\x8F\xBF\xBF",
		);

		foreach ( $complete as $label => $bytes ) {
			$length = strlen( $bytes );
			for ( $prefix_length = 1; $prefix_length < $length; $prefix_length++ ) {
				$prefix = substr( $bytes, 0, $prefix_length );
				self::add_case( $cases, "truncation:{$label}-{$prefix_length}", $prefix );
				self::add_case( $cases, "truncation:ascii-{$label}-{$prefix_length}", 'a' . $prefix );
			}
		}
	}

	/**
	 * @param array<int, array{label: string, bytes: string}> $cases
	 */
	private static function add_noncharacter_boundary_cases( array &$cases ): void {
		$code_points = array(
			0xFDCF,
			0xFDD0,
			0xFDEF,
			0xFDF0,
			0xFFFD,
			0xFFFE,
			0xFFFF,
		);

		for ( $plane = 0; $plane <= 0x10; $plane++ ) {
			$final         = ( $plane << 16 ) | 0xFFFF;
			$code_points[] = $final - 2;
			$code_points[] = $final - 1;
			$code_points[] = $final;
		}

		foreach ( array_values( array_unique( $code_points ) ) as $code_point ) {
			$bytes = Generator::encode_code_point( $code_point );
			$label = sprintf( 'noncharacter-boundary:u+%04x', $code_point );
			self::add_case( $cases, $label, $bytes );
			self::add_case( $cases, "{$label}-embedded", 'a' . $bytes . 'b' );
		}
	}

	/**
	 * @param array<int, array{label: string, bytes: string}> $cases
	 */
	private static function add_case( array &$cases, string $label, string $bytes ): void {
		$cases[] = array(
			'label' => $label,
			'bytes' => $bytes,
		);
	}

	/**
	 * @return array{0: int, 1: int}
	 */
	private static function three_byte_baseline( int $lead ): array {
		switch ( $lead ) {
			case 0xE0:
				return array( 0xA0, 0x80 );
			case 0xED:
				return array( 0x9F, 0xBF );
			default:
				return array( 0x80, 0x80 );
		}
	}

	/**
	 * @return array{0: int, 1: int, 2: int}
	 */
	private static function four_byte_baseline( int $lead ): array {
		switch ( $lead ) {
			case 0xF0:
				return array( 0x90, 0x80, 0x80 );
			case 0xF4:
				return array( 0x8F, 0xBF, 0xBF );
			default:
				return array( 0x80, 0x80, 0x80 );
		}
	}

	private static function bytes( int ...$bytes ): string {
		$out = '';
		foreach ( $bytes as $byte ) {
			$out .= chr( $byte );
		}
		return $out;
	}

	private static function hex_byte( int $byte ): string {
		return sprintf( '%02x', $byte );
	}
}
