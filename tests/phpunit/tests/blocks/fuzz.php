<?php
/**
 * Fuzz tests for block parsing, serialization, processing, and filtering.
 *
 * @package WordPress
 * @subpackage Blocks
 */

require_once dirname( __DIR__, 4 ) . '/tools/block-fuzz/lib/autoload.php';

/**
 * Tests block fuzz oracles.
 *
 * @group blocks
 * @group block-processor
 */
class Tests_Blocks_Fuzz extends WP_UnitTestCase {
	/**
	 * Checks deterministic generated fuzz inputs across every generator profile.
	 *
	 * @dataProvider data_fuzz_seeds
	 *
	 * @ticket 61401
	 * @ticket 63917
	 *
	 * @param int    $seed    Seed.
	 * @param string $profile Generator profile.
	 */
	public function test_generated_block_fuzz_oracles( $seed, $profile ) {
		$generated = BlockFuzz\Generator::generate(
			$seed,
			array(
				'profile'  => $profile,
				'maxBytes' => 3072,
			)
		);

		$metadata = $generated;
		unset( $metadata['input'] );
		$metadata['source'] = 'phpunit';

		$result = BlockFuzz\Fuzzer::run(
			$generated['input'],
			$metadata,
			array(
				'maxTokens'          => 1024,
				'maxSerializedBytes' => 65536,
			)
		);

		if ( ! empty( $metadata['expectProcessorAgreement'] ) ) {
			$this->assert_processor_agreement_oracle_did_not_skip( $result );
		}

		$this->assertTrue( $result['ok'], BlockFuzz\Fuzzer::failure_message( $result ) );
	}

	/**
	 * Checks that invalid block-looking comments do not automatically suppress
	 * the processor/parser agreement oracle when the caller requests it.
	 *
	 * @ticket 61401
	 */
	public function test_processor_parser_agreement_runs_with_invalid_block_like_comments() {
		$result = BlockFuzz\Fuzzer::run(
			'<!-- wp:group -->before<!-- wp:not-a-block because invalid -->after<!-- /wp:group -->',
			array(
				'source'                   => 'phpunit',
				'expectProcessorAgreement' => true,
			)
		);

		$this->assert_processor_agreement_oracle_did_not_skip( $result );
		$this->assertTrue( $result['ok'], BlockFuzz\Fuzzer::failure_message( $result ) );
	}

	/**
	 * Checks each hand-written malformed nesting case against the non-agreement oracles.
	 *
	 * @dataProvider data_invalid_nesting_cases
	 *
	 * @ticket 61401
	 * @ticket 63917
	 *
	 * @param string $input Invalid nesting input.
	 */
	public function test_invalid_nesting_fuzz_oracles( $input ) {
		$result = BlockFuzz\Fuzzer::run(
			$input,
			array(
				'source'                   => 'phpunit',
				'profile'                  => 'invalid-nesting',
				'expectProcessorAgreement' => false,
			),
			array(
				'maxTokens'          => 1024,
				'maxSerializedBytes' => 65536,
			)
		);

		$this->assertTrue( $result['ok'], BlockFuzz\Fuzzer::failure_message( $result ) );
	}

	/**
	 * Verifies one pass of filter_block_content() handles malformed nested EOF attributes.
	 *
	 * @ticket 61401
	 * @ticket 63917
	 */
	public function test_filter_block_content_is_idempotent_for_malformed_nested_eof_attributes() {
		$input    = '<!-- wp:outer --><!-- wp:inner {"x":"<script>alert(1)</script>","y":"--><img src=x onerror=alert(1)><!--"} -->';
		$filtered = filter_block_content( $input );

		$this->assertSame(
			$filtered,
			filter_block_content( $filtered ),
			'filter_block_content() should be idempotent for malformed nested EOF input.'
		);

		$result = BlockFuzz\Fuzzer::run(
			$input,
			array(
				'source'                   => 'phpunit',
				'expectProcessorAgreement' => false,
			),
			array(
				'maxTokens'          => 1024,
				'maxSerializedBytes' => 65536,
			)
		);

		$this->assertTrue( $result['ok'], BlockFuzz\Fuzzer::failure_message( $result ) );
	}

	/**
	 * Data provider.
	 *
	 * @return array[]
	 */
	public static function data_fuzz_seeds() {
		$cases = array();

		foreach ( BlockFuzz\Generator::profiles() as $profile ) {
			for ( $seed = 1; $seed <= 4; ++$seed ) {
				$cases[ "{$profile} seed {$seed}" ] = array( $seed, $profile );
			}
		}

		return $cases;
	}

	/**
	 * Data provider.
	 *
	 * @return array[]
	 */
	public static function data_invalid_nesting_cases() {
		$cases = array();

		foreach ( BlockFuzz\Generator::invalid_nesting_cases() as $index => $input ) {
			$cases[ 'invalid nesting case ' . ( $index + 1 ) ] = array( $input );
		}

		return $cases;
	}

	/**
	 * Checks that canonical block fixtures preserve serialization identity.
	 *
	 * @ticket 45109
	 * @ticket 63917
	 */
	public function test_block_fixture_corpus_preserves_canonical_serialization() {
		$result = BlockFuzz\Fuzzer::run_fixture_corpus( DIR_TESTDATA . '/blocks/fixtures' );

		$this->assertGreaterThan( 0, $result['metadata']['checked'], 'No serialized block fixtures were checked.' );
		$this->assertTrue( $result['ok'], BlockFuzz\Fuzzer::failure_message( $result ) );
	}

	/**
	 * Asserts that the processor/parser agreement oracle was not skipped.
	 *
	 * @param array $result Fuzz result.
	 */
	private function assert_processor_agreement_oracle_did_not_skip( $result ) {
		foreach ( $result['notes'] as $note ) {
			$this->assertFalse(
				'processor-parser-agreement' === $note['oracle'] && 'skipped' === $note['status'],
				'Processor/parser agreement oracle was unexpectedly skipped.'
			);
		}
	}
}
