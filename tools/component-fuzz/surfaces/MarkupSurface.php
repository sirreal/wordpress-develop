<?php
namespace ComponentFuzz\Surfaces;

/**
 * Fuzzes WordPress block, shortcode, and markup-processing helpers.
 */
final class MarkupSurface {
	public const NAME = 'markup';

	private const MAX_GENERATED_BYTES = 4096;
	private const DO_BLOCKS_FIXTURE_NAMES = array(
		'component-fuzz/static-a',
		'component-fuzz/static-b',
		'component-fuzz/static-c',
	);

	/**
	 * Runs one deterministic markup fuzz iteration.
	 *
	 * @param \ComponentFuzz\FuzzContext $ctx Fuzzer context supplied by the runner.
	 * @return array Structured surface result.
	 */
	public static function run( \ComponentFuzz\FuzzContext $ctx ): array {
		$seed      = self::seed_from_context( $ctx );
		$max_bytes = self::max_input_bytes_from_context( $ctx );
		$rng       = self::rng_create( $seed );
		$provided  = self::input_from_context( $ctx );
		$generated = null;

		if ( is_string( $provided ) ) {
			$input = self::trim_bytes( $provided, $max_bytes );
			$profile = 'context-input';
			$features = array( 'context-input' );
		} else {
			$generated = self::generate_markup( $rng, $max_bytes );
			$input     = $generated['input'];
			$profile   = $generated['profile'];
			$features  = $generated['features'];
		}

		$checks   = array();
		$failures = array();

		self::check_blocks( $input, $checks, $failures );
		self::check_shortcodes( $input, $checks, $failures );
		self::check_text_helpers( $input, $checks, $failures );
		self::check_embed_helpers( $checks, $failures );

		return self::rows_from_checks( $ctx, $input, $profile, $features, $generated, $checks, $failures );
	}

	private static function rows_from_checks( \ComponentFuzz\FuzzContext $ctx, string $input, string $profile, array $features, ?array $generated, array $checks, array $failures ): array {
		$common = array(
			'profile'      => $profile,
			'features'     => $features,
			'inputLength'  => strlen( $input ),
			'inputSha1'    => sha1( $input ),
			'inputPreview' => self::preview( $input ),
			'generator'    => $generated,
		);

		$rows = array();
		foreach ( $checks as $invariant => $check ) {
			$ok     = ! empty( $check['ok'] );
			$status = (string) ( $check['status'] ?? ( $ok ? 'passed' : 'failed' ) );
			$data   = $common + $check;
			unset( $data['ok'], $data['status'] );

			$rows[] = $ctx->result( (string) $invariant, $ok, $data, $status );
		}

		if ( empty( $rows ) ) {
			$rows[] = $ctx->skip( 'markup.no-checks', 'No markup checks ran.', $common );
		}

		if ( ! empty( $failures ) ) {
			$rows[] = $ctx->fail(
				'markup.failure-summary',
				$common + array(
					'failureCount' => count( $failures ),
					'failures'     => $failures,
				)
			);
		}

		return $rows;
	}

	private static function check_blocks( string $input, array &$checks, array &$failures ): void {
		if ( ! function_exists( 'parse_blocks' ) ) {
			self::skip( $checks, 'blocks.parse-serialize-stability', 'parse_blocks unavailable' );
			return;
		}

		if ( ! function_exists( 'serialize_blocks' ) ) {
			self::skip( $checks, 'blocks.parse-serialize-stability', 'serialize_blocks unavailable' );
			return;
		}

		$parsed_call = self::call(
			'parse_blocks',
			static function () use ( $input ) {
				return \parse_blocks( $input );
			}
		);

		if ( ! $parsed_call['ok'] || ! is_array( $parsed_call['value'] ) ) {
			self::fail_from_call( $checks, $failures, 'blocks.parse', $parsed_call, 'parse_blocks failed or returned a non-array value.' );
			return;
		}

		$blocks = $parsed_call['value'];

		$serialized_call = self::call(
			'serialize_blocks',
			static function () use ( $blocks ) {
				return \serialize_blocks( $blocks );
			}
		);

		if ( ! $serialized_call['ok'] || ! is_string( $serialized_call['value'] ) ) {
			self::fail_from_call( $checks, $failures, 'blocks.serialize', $serialized_call, 'serialize_blocks failed or returned a non-string value.' );
			return;
		}

		$serialized = $serialized_call['value'];
		$reparsed_call = self::call(
			'parse_blocks:serialized',
			static function () use ( $serialized ) {
				return \parse_blocks( $serialized );
			}
		);

		if ( ! $reparsed_call['ok'] || ! is_array( $reparsed_call['value'] ) ) {
			self::fail_from_call( $checks, $failures, 'blocks.reparse-serialized', $reparsed_call, 'parse_blocks failed on serialized block output.' );
			return;
		}

		$shape_before = self::block_tree_shape( $blocks );
		$shape_after  = self::block_tree_shape( $reparsed_call['value'] );
		if ( $shape_before !== $shape_after ) {
			self::fail(
				$checks,
				$failures,
				'blocks.parse-serialize-stability',
				'parse_blocks -> serialize_blocks -> parse_blocks changed the parsed block structure.',
				array(
					'blockCountBefore' => count( $blocks ),
					'blockCountAfter'  => count( $reparsed_call['value'] ),
					'beforeSha1'       => sha1( self::json( $shape_before ) ),
					'afterSha1'        => sha1( self::json( $shape_after ) ),
					'serializedLength' => strlen( $serialized ),
					'firstDifference'  => self::first_value_difference( $shape_before, $shape_after ),
				)
			);
		} else {
			self::pass(
				$checks,
				'blocks.parse-serialize-stability',
				array(
					'blockCount'       => count( $blocks ),
					'containsBlock'    => self::blocks_contain_named_block( $blocks ),
					'serializedLength' => strlen( $serialized ),
					'durationMs'       => $parsed_call['durationMs'] + $serialized_call['durationMs'] + $reparsed_call['durationMs'],
				)
			);
		}

		self::check_serialize_block_join( $blocks, $serialized, $checks, $failures );
		self::check_has_blocks( $input, $blocks, $serialized, $checks, $failures );
		self::check_do_blocks( $serialized, $reparsed_call['value'], $checks, $failures );
	}

	private static function check_serialize_block_join( array $blocks, string $serialized, array &$checks, array &$failures ): void {
		if ( ! function_exists( 'serialize_block' ) ) {
			self::skip( $checks, 'blocks.serialize-block-join', 'serialize_block unavailable' );
			return;
		}

		$pieces_call = self::call(
			'serialize_block:join',
			static function () use ( $blocks ) {
				$pieces = array();
				foreach ( $blocks as $block ) {
					$pieces[] = \serialize_block( $block );
				}
				return implode( '', $pieces );
			}
		);

		if ( ! $pieces_call['ok'] || ! is_string( $pieces_call['value'] ) ) {
			self::fail_from_call( $checks, $failures, 'blocks.serialize-block-join', $pieces_call, 'serialize_block failed while serializing individual top-level blocks.' );
			return;
		}

		if ( $serialized !== $pieces_call['value'] ) {
			self::fail(
				$checks,
				$failures,
				'blocks.serialize-block-join',
				'serialize_blocks differed from the concatenation of serialize_block over top-level blocks.',
				array(
					'serializeBlocksSha1' => sha1( $serialized ),
					'joinSha1'            => sha1( $pieces_call['value'] ),
					'firstDifference'     => self::first_string_difference( $serialized, $pieces_call['value'] ),
				)
			);
			return;
		}

		self::pass(
			$checks,
			'blocks.serialize-block-join',
			array(
				'blockCount' => count( $blocks ),
				'length'     => strlen( $serialized ),
				'durationMs' => $pieces_call['durationMs'],
			)
		);
	}

	private static function check_has_blocks( string $input, array $blocks, string $serialized, array &$checks, array &$failures ): void {
		if ( ! function_exists( 'has_blocks' ) ) {
			self::skip( $checks, 'blocks.has-blocks-agreement', 'has_blocks unavailable' );
			return;
		}

		$input_has_call = self::call(
			'has_blocks:input',
			static function () use ( $input ) {
				return \has_blocks( $input );
			}
		);
		$serialized_has_call = self::call(
			'has_blocks:serialized',
			static function () use ( $serialized ) {
				return \has_blocks( $serialized );
			}
		);
		$serialized_parse_call = self::call(
			'parse_blocks:has_blocks_serialized',
			static function () use ( $serialized ) {
				return \parse_blocks( $serialized );
			}
		);

		if ( ! $input_has_call['ok'] || ! $serialized_has_call['ok'] || ! $serialized_parse_call['ok'] || ! is_array( $serialized_parse_call['value'] ) ) {
			self::fail(
				$checks,
				$failures,
				'blocks.has-blocks-agreement',
				'has_blocks or parse_blocks failed while checking parser agreement.',
				array(
					'inputHasBlocksCall'      => self::call_summary( $input_has_call ),
					'serializedHasBlocksCall' => self::call_summary( $serialized_has_call ),
					'serializedParseCall'     => self::call_summary( $serialized_parse_call ),
				)
			);
			return;
		}

		$parser_has_input      = self::blocks_contain_named_block( $blocks );
		$parser_has_serialized = self::blocks_contain_named_block( $serialized_parse_call['value'] );
		$input_has             = (bool) $input_has_call['value'];
		$serialized_has        = (bool) $serialized_has_call['value'];

		if ( $parser_has_input && ! $input_has ) {
			self::fail(
				$checks,
				$failures,
				'blocks.has-blocks-agreement',
				'parse_blocks found a named block in the input, but has_blocks returned false.',
				array(
					'parserHasInput' => $parser_has_input,
					'hasInput'       => $input_has,
				)
			);
			return;
		}

		if ( $parser_has_serialized && ! $serialized_has ) {
			self::fail(
				$checks,
				$failures,
				'blocks.has-blocks-agreement',
				'parse_blocks found a named block in serialized output, but has_blocks returned false.',
				array(
					'parserHasSerialized' => $parser_has_serialized,
					'hasSerialized'       => $serialized_has,
					'serializedSha1'      => sha1( $serialized ),
				)
			);
			return;
		}

		self::pass(
			$checks,
			'blocks.has-blocks-agreement',
			array(
				'parserHasInput'      => $parser_has_input,
				'hasInput'            => $input_has,
				'parserHasSerialized' => $parser_has_serialized,
				'hasSerialized'       => $serialized_has,
				'optimizedFalsePositive' => $serialized_has && ! $parser_has_serialized,
			)
		);
	}

	private static function check_do_blocks( string $serialized, array $blocks, array &$checks, array &$failures ): void {
		if ( ! function_exists( 'do_blocks' ) ) {
			self::skip( $checks, 'blocks.do-blocks-deterministic', 'do_blocks unavailable' );
			return;
		}

		if ( ! class_exists( 'WP_Block' ) ) {
			self::skip( $checks, 'blocks.do-blocks-deterministic', 'WP_Block unavailable' );
			return;
		}

		$fixture = self::prepare_do_blocks_fixture( $serialized, $blocks, $checks, $failures );
		if ( null === $fixture ) {
			return;
		}

		$state_snapshot = self::do_blocks_state_snapshot();
		$render_log     = array();
		$registered     = true;
		$first_call     = null;
		$second_call    = null;
		$first_log      = array();
		$second_log     = array();

		try {
			if ( ! empty( $fixture['registerBlockNames'] ) ) {
				$registered = self::register_do_blocks_fixture_blocks( $fixture['registerBlockNames'], $render_log );
			}

			if ( $registered ) {
				$first_call = self::call(
					'do_blocks:first',
					static function () use ( $fixture ) {
						return \do_blocks( $fixture['serialized'] );
					}
				);
				$first_log  = $render_log;
				$render_log = array();

				$second_call = self::call(
					'do_blocks:second',
					static function () use ( $fixture ) {
						return \do_blocks( $fixture['serialized'] );
					}
				);
				$second_log  = $render_log;
			}
		} finally {
			self::restore_do_blocks_state( $state_snapshot );
		}

		$state_restored = self::do_blocks_state_matches_snapshot( $state_snapshot );
		if ( ! $state_restored ) {
			self::fail(
				$checks,
				$failures,
				'blocks.do-blocks-state-restored',
				'Block rendering state was not restored after deterministic do_blocks fixture rendering.',
				array(
					'fixtureBlockNames' => $fixture['fixtureBlockNames'],
				)
			);
		} else {
			self::pass(
				$checks,
				'blocks.do-blocks-state-restored',
				array(
					'registeredBlockNames' => $fixture['registerBlockNames'],
				)
			);
		}

		if ( ! $registered ) {
			self::fail(
				$checks,
				$failures,
				'blocks.do-blocks-deterministic',
				'Failed to register deterministic fixture block types for do_blocks.',
				array(
					'fixtureBlockNames' => $fixture['fixtureBlockNames'],
				)
			);
			return;
		}

		if ( null === $first_call || null === $second_call ) {
			self::fail(
				$checks,
				$failures,
				'blocks.do-blocks-deterministic',
				'do_blocks fixture calls were not executed.',
				array(
					'fixtureBlockNames' => $fixture['fixtureBlockNames'],
				)
			);
			return;
		}

		if ( ! $first_call['ok'] || ! $second_call['ok'] || ! is_string( $first_call['value'] ) || ! is_string( $second_call['value'] ) ) {
			self::fail(
				$checks,
				$failures,
				'blocks.do-blocks-deterministic',
				'do_blocks failed or returned a non-string value.',
				array(
					'firstCall'  => self::call_summary( $first_call ),
					'secondCall' => self::call_summary( $second_call ),
					'fixture'    => self::do_blocks_fixture_summary( $fixture ),
				)
			);
			return;
		}

		if ( $first_call['value'] !== $second_call['value'] ) {
			self::fail(
				$checks,
				$failures,
				'blocks.do-blocks-deterministic',
				'Two do_blocks calls on the same deterministic block fixture differed.',
				array(
					'firstSha1'       => sha1( $first_call['value'] ),
					'secondSha1'      => sha1( $second_call['value'] ),
					'firstDifference' => self::first_string_difference( $first_call['value'], $second_call['value'] ),
					'fixture'         => self::do_blocks_fixture_summary( $fixture ),
				)
			);
			return;
		}

		if ( $first_log !== $second_log ) {
			self::fail(
				$checks,
				$failures,
				'blocks.do-blocks-deterministic',
				'Deterministic fixture block callbacks received different payloads across repeated do_blocks calls.',
				array(
					'firstLog'  => $first_log,
					'secondLog' => $second_log,
					'fixture'   => self::do_blocks_fixture_summary( $fixture ),
				)
			);
			return;
		}

		if ( count( $first_log ) !== $fixture['namedBlockCount'] ) {
			self::fail(
				$checks,
				$failures,
				'blocks.do-blocks-deterministic',
				'Deterministic fixture block callbacks did not cover every named parsed block.',
				array(
					'callbackCount'   => count( $first_log ),
					'namedBlockCount' => $fixture['namedBlockCount'],
					'callbackLog'     => $first_log,
					'fixture'         => self::do_blocks_fixture_summary( $fixture ),
				)
			);
			return;
		}

		$wrapper_count = substr_count( $first_call['value'], 'data-cfz-block=' );
		if ( $wrapper_count !== $fixture['namedBlockCount'] ) {
			self::fail(
				$checks,
				$failures,
				'blocks.do-blocks-deterministic',
				'Rendered deterministic fixture wrapper count did not match the parsed named block count.',
				array(
					'wrapperCount'    => $wrapper_count,
					'namedBlockCount' => $fixture['namedBlockCount'],
					'outputSha1'      => sha1( $first_call['value'] ),
					'fixture'         => self::do_blocks_fixture_summary( $fixture ),
				)
			);
			return;
		}

		self::pass(
			$checks,
			'blocks.do-blocks-deterministic',
			array(
				'outputLength'    => strlen( $first_call['value'] ),
				'outputSha1'      => sha1( $first_call['value'] ),
				'durationMs'      => $first_call['durationMs'] + $second_call['durationMs'],
				'callbackCount'   => count( $first_log ),
				'namedBlockCount' => $fixture['namedBlockCount'],
				'fixture'         => self::do_blocks_fixture_summary( $fixture ),
			)
		);
	}

	private static function prepare_do_blocks_fixture( string $serialized, array $blocks, array &$checks, array &$failures ): ?array {
		if ( empty( $blocks ) || ! self::blocks_contain_named_block( $blocks ) ) {
			return array(
				'serialized'          => $serialized,
				'namedBlockCount'     => 0,
				'sourceBlockNames'    => array(),
				'fixtureBlockNames'   => array(),
				'registerBlockNames'  => array(),
				'sanitizedForRuntime' => false,
			);
		}

		if ( ! function_exists( 'serialize_blocks' ) || ! function_exists( 'register_block_type' ) || ! class_exists( 'WP_Block_Type_Registry' ) ) {
			self::skip( $checks, 'blocks.do-blocks-deterministic', 'serialize_blocks, register_block_type, or WP_Block_Type_Registry unavailable' );
			return null;
		}

		$source_names  = array();
		$fixture_names = array();
		$ordinal       = 0;
		$fixture_blocks = self::normalize_do_blocks_fixture_blocks(
			$blocks,
			$source_names,
			$fixture_names,
			$ordinal
		);

		$fixture_call = self::call(
			'serialize_blocks:do_blocks_fixture',
			static function () use ( $fixture_blocks ) {
				return \serialize_blocks( $fixture_blocks );
			}
		);

		if ( ! $fixture_call['ok'] || ! is_string( $fixture_call['value'] ) ) {
			self::fail_from_call( $checks, $failures, 'blocks.do-blocks-deterministic', $fixture_call, 'Failed to serialize deterministic do_blocks fixture blocks.' );
			return null;
		}

		return array(
			'serialized'          => $fixture_call['value'],
			'namedBlockCount'     => $ordinal,
			'sourceBlockNames'    => array_values( array_unique( $source_names ) ),
			'fixtureBlockNames'   => array_values( array_keys( $fixture_names ) ),
			'registerBlockNames'  => array_values( array_keys( $fixture_names ) ),
			'sanitizedForRuntime' => $fixture_call['value'] !== $serialized,
		);
	}

	private static function normalize_do_blocks_fixture_blocks( array $blocks, array &$source_names, array &$fixture_names, int &$ordinal ): array {
		$normalized = array();
		foreach ( $blocks as $block ) {
			if ( ! is_array( $block ) ) {
				$normalized[] = $block;
				continue;
			}

			$name = $block['blockName'] ?? null;
			if ( is_string( $name ) && '' !== $name ) {
				++$ordinal;
				$fixture_name = self::DO_BLOCKS_FIXTURE_NAMES[ ( $ordinal - 1 ) % count( self::DO_BLOCKS_FIXTURE_NAMES ) ];
				$source_names[] = $name;
				$fixture_names[ $fixture_name ] = true;
				$block['blockName'] = $fixture_name;
				$block['attrs'] = is_array( $block['attrs'] ?? null ) ? $block['attrs'] : array();
				$block['attrs']['__cfzOriginalName'] = $name;
				$block['attrs']['__cfzOrdinal']      = $ordinal;
			}

			if ( ! empty( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ) {
				$block['innerBlocks'] = self::normalize_do_blocks_fixture_blocks(
					$block['innerBlocks'],
					$source_names,
					$fixture_names,
					$ordinal
				);
			}

			$normalized[] = $block;
		}

		return $normalized;
	}

	private static function register_do_blocks_fixture_blocks( array $names, array &$render_log ): bool {
		$registry = \WP_Block_Type_Registry::get_instance();
		foreach ( $names as $name ) {
			if ( $registry->is_registered( $name ) ) {
				return false;
			}

			$registered = \register_block_type(
				$name,
				array(
					'supports'        => array(),
					'render_callback' => static function ( array $attributes, string $content, $block ) use ( &$render_log, $name ): string {
						$block_name = is_object( $block ) && isset( $block->name ) && is_string( $block->name ) ? $block->name : $name;
						$attributes = self::sort_value( $attributes );
						$attr_sha1  = sha1( self::json( $attributes ) );
						$render_log[] = array(
							'name'          => $block_name,
							'originalName'  => is_string( $attributes['__cfzOriginalName'] ?? null ) ? $attributes['__cfzOriginalName'] : null,
							'ordinal'       => is_int( $attributes['__cfzOrdinal'] ?? null ) ? $attributes['__cfzOrdinal'] : null,
							'attrsSha1'     => $attr_sha1,
							'contentLength' => strlen( $content ),
							'contentSha1'   => sha1( $content ),
						);

						return '<div data-cfz-block="' . htmlspecialchars( $block_name, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' ) . '" data-cfz-attrs="' . substr( $attr_sha1, 0, 12 ) . '">' . $content . '</div>';
					},
				)
			);

			if ( false === $registered ) {
				return false;
			}
		}

		return true;
	}

	private static function do_blocks_fixture_summary( array $fixture ): array {
		return array(
			'namedBlockCount'     => $fixture['namedBlockCount'],
			'sourceBlockNames'    => $fixture['sourceBlockNames'],
			'fixtureBlockNames'   => $fixture['fixtureBlockNames'],
			'sanitizedForRuntime' => $fixture['sanitizedForRuntime'],
			'serializedLength'    => strlen( $fixture['serialized'] ),
			'serializedSha1'      => sha1( $fixture['serialized'] ),
		);
	}

	private static function do_blocks_state_snapshot(): array {
		$block_registry = self::get_static_property( 'WP_Block_Type_Registry', 'instance' );

		return array(
			'obLevel'              => ob_get_level(),
			'globals'              => self::snapshot_globals(
				array(
					'post',
					'wp_actions',
					'wp_current_filter',
					'wp_filter',
					'wp_filters',
					'wp_interactivity',
					'wp_script_modules',
					'wp_scripts',
					'wp_styles',
				)
			),
			'blockRegistry'        => $block_registry,
			'registeredBlockTypes' => $block_registry instanceof \WP_Block_Type_Registry ? self::get_object_property( $block_registry, 'registered_block_types' ) : null,
			'blockToRender'        => self::get_static_property( 'WP_Block_Supports', 'block_to_render' ),
		);
	}

	private static function restore_do_blocks_state( array $snapshot ): void {
		while ( ob_get_level() > $snapshot['obLevel'] ) {
			ob_end_clean();
		}

		self::restore_globals( $snapshot['globals'] );

		if ( $snapshot['blockRegistry'] instanceof \WP_Block_Type_Registry ) {
			self::set_object_property( $snapshot['blockRegistry'], 'registered_block_types', $snapshot['registeredBlockTypes'] );
			self::set_static_property( 'WP_Block_Type_Registry', 'instance', $snapshot['blockRegistry'] );
		} else {
			self::set_static_property( 'WP_Block_Type_Registry', 'instance', null );
		}

		self::set_static_property( 'WP_Block_Supports', 'block_to_render', $snapshot['blockToRender'] );
	}

	private static function do_blocks_state_matches_snapshot( array $snapshot ): bool {
		if ( ob_get_level() !== $snapshot['obLevel'] ) {
			return false;
		}

		foreach ( $snapshot['globals'] as $name => $entry ) {
			if ( array_key_exists( $name, $GLOBALS ) !== $entry['exists'] ) {
				return false;
			}
			if ( $entry['exists'] && $GLOBALS[ $name ] != $entry['value'] ) {
				return false;
			}
		}

		$block_registry = self::get_static_property( 'WP_Block_Type_Registry', 'instance' );
		if ( ( $snapshot['blockRegistry'] instanceof \WP_Block_Type_Registry ) !== ( $block_registry instanceof \WP_Block_Type_Registry ) ) {
			return false;
		}
		if ( $block_registry instanceof \WP_Block_Type_Registry && self::get_object_property( $block_registry, 'registered_block_types' ) != $snapshot['registeredBlockTypes'] ) {
			return false;
		}

		return self::get_static_property( 'WP_Block_Supports', 'block_to_render' ) === $snapshot['blockToRender'];
	}

	private static function snapshot_globals( array $names ): array {
		$snapshot = array();
		foreach ( $names as $name ) {
			$snapshot[ $name ] = array(
				'exists' => array_key_exists( $name, $GLOBALS ),
				'value'  => array_key_exists( $name, $GLOBALS ) ? self::clone_value( $GLOBALS[ $name ] ) : null,
			);
		}

		return $snapshot;
	}

	private static function restore_globals( array $snapshot ): void {
		foreach ( $snapshot as $name => $entry ) {
			if ( $entry['exists'] ) {
				$GLOBALS[ $name ] = self::clone_value( $entry['value'] );
			} else {
				unset( $GLOBALS[ $name ] );
			}
		}
	}

	private static function get_static_property( string $class, string $property ) {
		if ( ! class_exists( $class ) || ! property_exists( $class, $property ) ) {
			return null;
		}

		$reflection = new \ReflectionProperty( $class, $property );
		return $reflection->getValue();
	}

	private static function set_static_property( string $class, string $property, $value ): void {
		if ( ! class_exists( $class ) || ! property_exists( $class, $property ) ) {
			return;
		}

		$reflection = new \ReflectionProperty( $class, $property );
		$reflection->setValue( null, $value );
	}

	private static function get_object_property( object $object, string $property ) {
		$reflection = new \ReflectionProperty( $object, $property );
		return $reflection->getValue( $object );
	}

	private static function set_object_property( object $object, string $property, $value ): void {
		try {
			$reflection = new \ReflectionObject( $object );
			$property_reflection = $reflection->getProperty( $property );
			if ( PHP_VERSION_ID < 80100 ) {
				$property_reflection->setAccessible( true );
			}
			$property_reflection->setValue( $object, $value );
		} catch ( \ReflectionException $e ) {
			// A failed restoration is reported by do_blocks_state_matches_snapshot().
		}
	}

	private static function clone_value( $value ) {
		if ( is_array( $value ) ) {
			$copy = array();
			foreach ( $value as $key => $item ) {
				$copy[ $key ] = self::clone_value( $item );
			}
			return $copy;
		}

		if ( is_object( $value ) ) {
			return clone $value;
		}

		return $value;
	}

	private static function check_shortcodes( string $input, array &$checks, array &$failures ): void {
		if ( function_exists( 'shortcode_parse_atts' ) ) {
			self::check_shortcode_atts( $checks, $failures );
		} else {
			self::skip( $checks, 'shortcodes.parse-atts-key-values', 'shortcode_parse_atts unavailable' );
		}

		if ( ! function_exists( 'get_shortcode_regex' ) ) {
			self::skip( $checks, 'shortcodes.regex-bounded', 'get_shortcode_regex unavailable' );
		}

		if ( ! function_exists( 'add_shortcode' ) || ! function_exists( 'do_shortcode' ) || ! function_exists( 'strip_shortcodes' ) || ! function_exists( 'get_shortcode_regex' ) ) {
			self::skip( $checks, 'shortcodes.local-callbacks', 'shortcode registry helpers unavailable' );
			return;
		}

		$had_shortcode_tags = array_key_exists( 'shortcode_tags', $GLOBALS );
		$snapshot           = $had_shortcode_tags ? $GLOBALS['shortcode_tags'] : null;
		$calls              = array();

		try {
			$GLOBALS['shortcode_tags'] = is_array( $GLOBALS['shortcode_tags'] ?? null ) ? $GLOBALS['shortcode_tags'] : array();

			$callback = static function ( $atts, $content = null, $tag = '' ) use ( &$calls ) {
				$atts = is_array( $atts ) ? self::sort_value( $atts ) : array();
				if ( null !== $content && function_exists( 'do_shortcode' ) ) {
					$content = \do_shortcode( $content );
				}
				$content = null === $content ? '' : (string) $content;
				$calls[] = array(
					'tag'         => (string) $tag,
					'attrsSha1'   => sha1( self::json( $atts ) ),
					'contentSha1' => sha1( $content ),
				);
				return '{cfz:' . (string) $tag . ':' . substr( sha1( self::json( $atts ) . "\0" . $content ), 0, 12 ) . '}';
			};

			\add_shortcode( 'cfz_a', $callback );
			\add_shortcode( 'cfz_b', $callback );
			\add_shortcode( 'cfz_echo', $callback );

			self::check_shortcode_regex_storm( $input, $checks, $failures );

			$content = 'pre [cfz_a alpha="one two" beta=\'quo\\\'ted\' bare=word]inner [cfz_b nested="[x]" slash="a\\/b" /] tail[/cfz_a] [[cfz_echo escaped]] post [unknown x="1"]';
			$first_call = self::call(
				'do_shortcode:first',
				static function () use ( $content ) {
					return \do_shortcode( $content );
				}
			);
			$first_calls = $calls;
			$calls       = array();
			$second_call = self::call(
				'do_shortcode:second',
				static function () use ( $content ) {
					return \do_shortcode( $content );
				}
			);
			$second_calls = $calls;

			$strip_input = 'before [cfz_a x="1"]body [cfz_b /][/cfz_a] after';
			$strip_call  = self::call(
				'strip_shortcodes',
				static function () use ( $strip_input ) {
					return \strip_shortcodes( $strip_input );
				}
			);

			if ( ! $first_call['ok'] || ! $second_call['ok'] || ! $strip_call['ok'] || ! is_string( $first_call['value'] ) || ! is_string( $second_call['value'] ) || ! is_string( $strip_call['value'] ) ) {
				self::fail(
					$checks,
					$failures,
					'shortcodes.local-callbacks',
					'do_shortcode or strip_shortcodes failed with local dummy tags.',
					array(
						'firstCall'  => self::call_summary( $first_call ),
						'secondCall' => self::call_summary( $second_call ),
						'stripCall'  => self::call_summary( $strip_call ),
					)
				);
			} elseif ( $first_call['value'] !== $second_call['value'] || $first_calls !== $second_calls ) {
				self::fail(
					$checks,
					$failures,
					'shortcodes.local-callbacks',
					'Local shortcode callbacks were not deterministic.',
					array(
						'firstOutputSha1'  => sha1( $first_call['value'] ),
						'secondOutputSha1' => sha1( $second_call['value'] ),
						'firstCalls'       => $first_calls,
						'secondCalls'      => $second_calls,
					)
				);
			} elseif ( count( $first_calls ) < 2 ) {
				self::fail(
					$checks,
					$failures,
					'shortcodes.local-callbacks',
					'Nested local shortcode callbacks did not execute.',
					array(
						'calls'      => $first_calls,
						'outputSha1' => sha1( $first_call['value'] ),
					)
				);
			} elseif ( false !== strpos( $strip_call['value'], '[cfz_a' ) || false !== strpos( $strip_call['value'], '[cfz_b' ) ) {
				self::fail(
					$checks,
					$failures,
					'shortcodes.local-callbacks',
					'strip_shortcodes left a registered dummy shortcode tag in the output.',
					array(
						'strippedPreview' => self::preview( $strip_call['value'] ),
					)
				);
			} else {
				self::pass(
					$checks,
					'shortcodes.local-callbacks',
					array(
						'callbackCount' => count( $first_calls ),
						'outputSha1'    => sha1( $first_call['value'] ),
						'stripSha1'     => sha1( $strip_call['value'] ),
						'durationMs'    => $first_call['durationMs'] + $second_call['durationMs'] + $strip_call['durationMs'],
					)
				);
			}
		} finally {
			if ( $had_shortcode_tags ) {
				$GLOBALS['shortcode_tags'] = $snapshot;
			} else {
				unset( $GLOBALS['shortcode_tags'] );
			}
		}

		$restored = $had_shortcode_tags
			? ( array_key_exists( 'shortcode_tags', $GLOBALS ) && $GLOBALS['shortcode_tags'] === $snapshot )
			: ! array_key_exists( 'shortcode_tags', $GLOBALS );

		if ( ! $restored ) {
			self::fail(
				$checks,
				$failures,
				'shortcodes.registry-restored',
				'The global shortcode registry was not restored after local dummy shortcode checks.',
				array()
			);
		} else {
			self::pass( $checks, 'shortcodes.registry-restored', array( 'hadRegistryBefore' => $had_shortcode_tags ) );
		}
	}

	private static function check_shortcode_atts( array &$checks, array &$failures ): void {
		$attr_text = 'Alpha="one" beta=\'two two\' gamma=three path="a\\\\b"';
		$call = self::call(
			'shortcode_parse_atts',
			static function () use ( $attr_text ) {
				return \shortcode_parse_atts( $attr_text );
			}
		);

		if ( ! $call['ok'] || ! is_array( $call['value'] ) ) {
			self::fail_from_call( $checks, $failures, 'shortcodes.parse-atts-key-values', $call, 'shortcode_parse_atts failed or returned a non-array value.' );
			return;
		}

		$atts     = $call['value'];
		$expected = array(
			'alpha'   => 'one',
			'beta'    => 'two two',
			'gamma'   => 'three',
			'path'    => 'a\\b',
		);

		foreach ( $expected as $key => $value ) {
			if ( ! array_key_exists( $key, $atts ) || $atts[ $key ] !== $value ) {
				self::fail(
					$checks,
					$failures,
					'shortcodes.parse-atts-key-values',
					'shortcode_parse_atts lost or changed a simple generated key/value attribute.',
					array(
						'attrText' => $attr_text,
						'expected' => $expected,
						'actual'   => self::sort_value( $atts ),
					)
				);
				return;
			}
		}

		self::pass(
			$checks,
			'shortcodes.parse-atts-key-values',
			array(
				'keys'       => array_keys( $expected ),
				'actualSha1' => sha1( self::json( self::sort_value( $atts ) ) ),
				'durationMs' => $call['durationMs'],
			)
		);
	}

	private static function check_shortcode_regex_storm( string $input, array &$checks, array &$failures ): void {
		if ( ! function_exists( 'get_shortcode_regex' ) ) {
			self::skip( $checks, 'shortcodes.regex-bounded', 'get_shortcode_regex unavailable' );
			return;
		}

		$storm = self::bracket_storm( $input );
		$regex_call = self::call(
			'get_shortcode_regex/preg_match_all',
			static function () use ( $storm ) {
				$pattern = \get_shortcode_regex( array( 'cfz_a', 'cfz_b', 'cfz_echo' ) );
				$matches = array();
				$count = preg_match_all( '/' . $pattern . '/', $storm, $matches );
				return array(
					'count'         => $count,
					'pregLastError' => function_exists( 'preg_last_error' ) ? preg_last_error() : 0,
				);
			}
		);

		if ( ! $regex_call['ok'] || ! is_array( $regex_call['value'] ) ) {
			self::fail_from_call( $checks, $failures, 'shortcodes.regex-bounded', $regex_call, 'Shortcode regex failed on a bounded bracket storm.' );
			return;
		}

		if ( false === $regex_call['value']['count'] || 0 !== $regex_call['value']['pregLastError'] ) {
			self::fail(
				$checks,
				$failures,
				'shortcodes.regex-bounded',
				'Shortcode regex returned a PCRE error on a bounded bracket storm.',
				array(
					'pregLastError' => $regex_call['value']['pregLastError'],
					'stormLength'   => strlen( $storm ),
				)
			);
			return;
		}

		if ( $regex_call['durationMs'] > 2000.0 ) {
			self::fail(
				$checks,
				$failures,
				'shortcodes.regex-bounded',
				'Shortcode regex took too long on a bounded bracket storm.',
				array(
					'durationMs' => $regex_call['durationMs'],
					'stormLength' => strlen( $storm ),
				)
			);
			return;
		}

		self::pass(
			$checks,
			'shortcodes.regex-bounded',
			array(
				'matchCount'   => $regex_call['value']['count'],
				'stormLength'  => strlen( $storm ),
				'durationMs'   => $regex_call['durationMs'],
			)
		);
	}

	private static function check_text_helpers( string $input, array &$checks, array &$failures ): void {
		if ( function_exists( 'wp_strip_all_tags' ) ) {
			$strip_call = self::call(
				'wp_strip_all_tags:idempotence',
				static function () use ( $input ) {
					$once = \wp_strip_all_tags( $input );
					$twice = \wp_strip_all_tags( $once );
					$compact_once = \wp_strip_all_tags( $input, true );
					$compact_twice = \wp_strip_all_tags( $compact_once, true );
					return array( $once, $twice, $compact_once, $compact_twice );
				}
			);

			if ( ! $strip_call['ok'] || ! is_array( $strip_call['value'] ) ) {
				self::fail_from_call( $checks, $failures, 'markup.strip-all-tags-idempotent', $strip_call, 'wp_strip_all_tags failed during idempotence check.' );
			} elseif ( $strip_call['value'][0] !== $strip_call['value'][1] || $strip_call['value'][2] !== $strip_call['value'][3] ) {
				self::fail(
					$checks,
					$failures,
					'markup.strip-all-tags-idempotent',
					'wp_strip_all_tags changed already stripped output.',
					array(
						'onceSha1'        => sha1( $strip_call['value'][0] ),
						'twiceSha1'       => sha1( $strip_call['value'][1] ),
						'compactOnceSha1' => sha1( $strip_call['value'][2] ),
						'compactTwiceSha1'=> sha1( $strip_call['value'][3] ),
					)
				);
			} else {
				self::pass(
					$checks,
					'markup.strip-all-tags-idempotent',
					array(
						'outputLength' => strlen( $strip_call['value'][0] ),
						'outputSha1'   => sha1( $strip_call['value'][0] ),
						'durationMs'   => $strip_call['durationMs'],
					)
				);
			}
		} else {
			self::skip( $checks, 'markup.strip-all-tags-idempotent', 'wp_strip_all_tags unavailable' );
		}

		if ( function_exists( 'force_balance_tags' ) && function_exists( 'wp_strip_all_tags' ) ) {
			$balance_call = self::call(
				'force_balance_tags:sanitized-idempotence',
				static function () use ( $input ) {
					$balanced = \force_balance_tags( $input );
					$balanced_twice = \force_balance_tags( $balanced );
					return array(
						$balanced,
						$balanced_twice,
						\wp_strip_all_tags( $balanced ),
						\wp_strip_all_tags( $balanced_twice ),
					);
				}
			);

			if ( ! $balance_call['ok'] || ! is_array( $balance_call['value'] ) ) {
				self::fail_from_call( $checks, $failures, 'markup.force-balance-sanitized-idempotent', $balance_call, 'force_balance_tags failed during sanitized idempotence check.' );
			} elseif ( $balance_call['value'][2] !== $balance_call['value'][3] ) {
				self::fail(
					$checks,
					$failures,
					'markup.force-balance-sanitized-idempotent',
					'Sanitized force_balance_tags output changed after balancing a second time.',
					array(
						'balancedSha1'       => sha1( $balance_call['value'][0] ),
						'balancedTwiceSha1'  => sha1( $balance_call['value'][1] ),
						'sanitizedSha1'      => sha1( $balance_call['value'][2] ),
						'sanitizedTwiceSha1' => sha1( $balance_call['value'][3] ),
					)
				);
			} else {
				self::pass(
					$checks,
					'markup.force-balance-sanitized-idempotent',
					array(
						'balancedLength' => strlen( $balance_call['value'][0] ),
						'sanitizedSha1'  => sha1( $balance_call['value'][2] ),
						'durationMs'     => $balance_call['durationMs'],
					)
				);
			}
		} else {
			self::skip( $checks, 'markup.force-balance-sanitized-idempotent', 'force_balance_tags or wp_strip_all_tags unavailable' );
		}

		self::check_excerpt_helpers( $input, $checks, $failures );
		self::check_link_attribute_helpers( $checks, $failures );
	}

	private static function check_link_attribute_helpers( array &$checks, array &$failures ): void {
		if ( ! function_exists( 'links_add_target' ) || ! function_exists( 'wp_rel_nofollow' ) || ! function_exists( 'wp_internal_hosts' ) || ! class_exists( 'WP_HTML_Tag_Processor' ) ) {
			self::skip( $checks, 'markup.link-attribute-helpers', 'links_add_target, wp_rel_nofollow, wp_internal_hosts, or WP_HTML_Tag_Processor unavailable' );
			return;
		}

		$external_host = self::external_fixture_host();
		if ( null === $external_host ) {
			self::skip( $checks, 'markup.link-attribute-helpers', 'No generated host was outside wp_internal_hosts().' );
			return;
		}

		$external_href      = 'https://' . $external_host . '/a?x=1&y=2';
		$external_href_attr = 'https://' . $external_host . '/a?x=1&amp;y=2';
		$second_href        = 'https://' . $external_host . '/second';
		$expected_hrefs     = array( $external_href, '/local', $second_href );
		$html               = '<p><a href="' . $external_href_attr . '">external</a> <a href="/local" rel="tag" target="_self">local</a> <span><a data-cfz="1" href="' . $second_href . '">second</a></span></p>';
		$target             = 'cfz-target-"probe';

		$had_links_target = array_key_exists( '_links_add_target', $GLOBALS );
		$links_target     = $GLOBALS['_links_add_target'] ?? null;

		try {
			$target_call = self::call(
				'links_add_target:anchor-attrs',
				static function () use ( $html, $target ) {
					return \links_add_target( $html, $target, array( 'a' ) );
				}
			);
			$nofollow_call = self::call(
				'wp_rel_nofollow:anchor-attrs',
				static function () use ( $html ) {
					return \wp_rel_nofollow( $html );
				}
			);
		} finally {
			if ( $had_links_target ) {
				$GLOBALS['_links_add_target'] = $links_target;
			} else {
				unset( $GLOBALS['_links_add_target'] );
			}
		}

		$global_restored = $had_links_target
			? ( array_key_exists( '_links_add_target', $GLOBALS ) && $GLOBALS['_links_add_target'] === $links_target )
			: ! array_key_exists( '_links_add_target', $GLOBALS );

		if ( ! $target_call['ok'] || ! is_string( $target_call['value'] ) || ! $nofollow_call['ok'] || ! is_string( $nofollow_call['value'] ) ) {
			self::fail(
				$checks,
				$failures,
				'markup.link-attribute-helpers',
				'Link attribute helpers failed or returned non-string output.',
				array(
					'targetCall'    => self::call_summary( $target_call ),
					'nofollowCall'  => self::call_summary( $nofollow_call ),
					'globalRestored' => $global_restored,
				)
			);
			return;
		}

		$target_attrs = self::anchor_attributes( $target_call['value'] );
		$nofollow_html = function_exists( 'wp_unslash' ) ? \wp_unslash( $nofollow_call['value'] ) : stripslashes( $nofollow_call['value'] );
		$nofollow_attrs = self::anchor_attributes( $nofollow_html );

		$target_values = array_column( $target_attrs, 'target' );
		$target_hrefs  = array_column( $target_attrs, 'href' );
		$target_ok     = 3 === count( $target_attrs )
			&& $expected_hrefs === $target_hrefs
			&& array( $target, $target, $target ) === $target_values
			&& null === $target_attrs[0]['rel']
			&& null === $target_attrs[0]['data-cfz']
			&& 'tag' === $target_attrs[1]['rel']
			&& '1' === $target_attrs[2]['data-cfz']
			&& false === strpos( $target_call['value'], 'target="_self"' );

		$nofollow_hrefs = array_column( $nofollow_attrs, 'href' );
		$nofollow_ok = 3 === count( $nofollow_attrs )
			&& $expected_hrefs === $nofollow_hrefs
			&& 1 === self::rel_token_count( $nofollow_attrs[0]['rel'], 'nofollow' )
			&& 1 === self::rel_token_count( $nofollow_attrs[1]['rel'], 'nofollow' )
			&& 1 === self::rel_token_count( $nofollow_attrs[1]['rel'], 'tag' )
			&& 1 === self::rel_token_count( $nofollow_attrs[2]['rel'], 'nofollow' )
			&& '_self' === $nofollow_attrs[1]['target']
			&& null === $nofollow_attrs[0]['target']
			&& null === $nofollow_attrs[2]['target']
			&& null === $nofollow_attrs[0]['data-cfz']
			&& '1' === $nofollow_attrs[2]['data-cfz'];

		if ( ! $target_ok || ! $nofollow_ok || ! $global_restored ) {
			self::fail(
				$checks,
				$failures,
				'markup.link-attribute-helpers',
				'Link attribute helpers did not preserve expected anchor attributes and rel token invariants.',
				array(
					'targetAttrs'    => $target_attrs,
					'nofollowAttrs'  => $nofollow_attrs,
					'targetOutput'   => self::preview( $target_call['value'] ),
					'nofollowOutput' => self::preview( $nofollow_html ),
					'globalRestored' => $global_restored,
					'externalHost'   => $external_host,
					'internalHosts'  => \wp_internal_hosts(),
				)
			);
			return;
		}

		self::pass(
			$checks,
			'markup.link-attribute-helpers',
			array(
				'anchorCount'    => count( $target_attrs ),
				'targetSha1'     => sha1( $target_call['value'] ),
				'nofollowSha1'   => sha1( $nofollow_html ),
				'externalHost'   => $external_host,
				'globalRestored' => $global_restored,
				'durationMs'     => $target_call['durationMs'] + $nofollow_call['durationMs'],
			)
		);
	}

	private static function check_excerpt_helpers( string $input, array &$checks, array &$failures ): void {
		$ran = false;

		if ( function_exists( 'wp_html_excerpt' ) ) {
			$ran = true;
			$call = self::call(
				'wp_html_excerpt:deterministic',
				static function () use ( $input ) {
					return array(
						\wp_html_excerpt( $input, 48, '...' ),
						\wp_html_excerpt( $input, 48, '...' ),
					);
				}
			);
			self::check_pair_determinism( $call, $checks, $failures, 'markup.wp-html-excerpt-deterministic', 'wp_html_excerpt' );
		}

		if ( function_exists( 'wp_trim_words' ) && function_exists( 'wp_get_word_count_type' ) && function_exists( 'get_option' ) ) {
			$ran = true;
			$call = self::call(
				'wp_trim_words:deterministic',
				static function () use ( $input ) {
					return array(
						\wp_trim_words( $input, 12, '...' ),
						\wp_trim_words( $input, 12, '...' ),
					);
				}
			);
			self::check_pair_determinism( $call, $checks, $failures, 'markup.wp-trim-words-deterministic', 'wp_trim_words' );
		} elseif ( function_exists( 'wp_trim_words' ) ) {
			self::skip( $checks, 'markup.wp-trim-words-deterministic', 'wp_trim_words dependencies unavailable' );
		}

		if ( function_exists( 'excerpt_remove_footnotes' ) ) {
			$ran = true;
			$footnote = 'A<sup data-fn="1" class="fn"><a href="#fn-1" id="fnref-1">1</a></sup>B';
			$call = self::call(
				'excerpt_remove_footnotes',
				static function () use ( $footnote ) {
					return \excerpt_remove_footnotes( $footnote );
				}
			);
			if ( ! $call['ok'] || ! is_string( $call['value'] ) ) {
				self::fail_from_call( $checks, $failures, 'markup.excerpt-remove-footnotes', $call, 'excerpt_remove_footnotes failed.' );
			} elseif ( false !== strpos( $call['value'], 'data-fn=' ) ) {
				self::fail(
					$checks,
					$failures,
					'markup.excerpt-remove-footnotes',
					'excerpt_remove_footnotes left generated footnote markup in place.',
					array( 'outputPreview' => self::preview( $call['value'] ) )
				);
			} else {
				self::pass( $checks, 'markup.excerpt-remove-footnotes', array( 'outputSha1' => sha1( $call['value'] ), 'durationMs' => $call['durationMs'] ) );
			}
		}

		if ( function_exists( 'excerpt_remove_blocks' ) && function_exists( 'parse_blocks' ) && function_exists( 'render_block' ) && class_exists( 'WP_Block' ) ) {
			$ran = true;
			$block_excerpt = '<!-- wp:paragraph --><p>Excerpt text <strong>here</strong>.</p><!-- /wp:paragraph -->';
			$call = self::call(
				'excerpt_remove_blocks:deterministic',
				static function () use ( $block_excerpt ) {
					return array(
						\excerpt_remove_blocks( $block_excerpt ),
						\excerpt_remove_blocks( $block_excerpt ),
					);
				}
			);
			self::check_pair_determinism( $call, $checks, $failures, 'markup.excerpt-remove-blocks-deterministic', 'excerpt_remove_blocks' );
		} elseif ( function_exists( 'excerpt_remove_blocks' ) ) {
			self::skip( $checks, 'markup.excerpt-remove-blocks-deterministic', 'excerpt_remove_blocks dependencies unavailable' );
		}

		if ( function_exists( 'get_url_in_content' ) && class_exists( 'WP_HTML_Tag_Processor' ) ) {
			$ran = true;
			$url_input = '<p>x <a href="https://example.test/path?a=1&amp;b=2">link</a></p>';
			$call = self::call(
				'get_url_in_content',
				static function () use ( $url_input ) {
					return \get_url_in_content( $url_input );
				}
			);
			if ( ! $call['ok'] || ! is_string( $call['value'] ) || false === strpos( $call['value'], 'example.test/path' ) ) {
				self::fail(
					$checks,
					$failures,
					'markup.get-url-in-content',
					'get_url_in_content did not return the generated anchor URL.',
					array(
						'call'  => self::call_summary( $call ),
						'value' => is_scalar( $call['value'] ?? null ) ? (string) $call['value'] : null,
					)
				);
			} else {
				self::pass( $checks, 'markup.get-url-in-content', array( 'urlSha1' => sha1( $call['value'] ), 'durationMs' => $call['durationMs'] ) );
			}
		}

		if ( ! $ran ) {
			self::skip( $checks, 'markup.excerpt-text-helpers', 'excerpt/text helper functions unavailable' );
		}
	}

	private static function check_embed_helpers( array &$checks, array &$failures ): void {
		self::load_wp_include( 'wp-includes/embed.php' );

		if ( function_exists( 'wp_oembed_ensure_format' ) ) {
			$call = self::call(
				'wp_oembed_ensure_format',
				static function () {
					return array(
						\wp_oembed_ensure_format( 'json' ),
						\wp_oembed_ensure_format( 'xml' ),
						\wp_oembed_ensure_format( 'html' ),
					);
				}
			);
			if ( ! $call['ok'] || array( 'json', 'xml', 'json' ) !== $call['value'] ) {
				self::fail(
					$checks,
					$failures,
					'embed.ensure-format',
					'wp_oembed_ensure_format did not preserve json/xml and coerce an unknown format to json.',
					array( 'call' => self::call_summary( $call ), 'value' => $call['value'] ?? null )
				);
			} else {
				self::pass( $checks, 'embed.ensure-format', array( 'durationMs' => $call['durationMs'] ) );
			}
		} else {
			self::skip( $checks, 'embed.ensure-format', 'wp_oembed_ensure_format unavailable' );
		}

		if ( function_exists( 'wp_embed_defaults' ) ) {
			$call = self::call(
				'wp_embed_defaults',
				static function () {
					return array(
						\wp_embed_defaults( 'https://example.test/video' ),
						\wp_embed_defaults( 'https://example.test/video' ),
					);
				}
			);
			if ( ! $call['ok'] || ! is_array( $call['value'] ) || $call['value'][0] !== $call['value'][1] || empty( $call['value'][0]['width'] ) || empty( $call['value'][0]['height'] ) ) {
				self::fail(
					$checks,
					$failures,
					'embed.defaults-deterministic',
					'wp_embed_defaults was not deterministic or did not return width/height.',
					array( 'call' => self::call_summary( $call ), 'value' => $call['value'] ?? null )
				);
			} else {
				self::pass( $checks, 'embed.defaults-deterministic', array( 'defaults' => $call['value'][0], 'durationMs' => $call['durationMs'] ) );
			}
		} else {
			self::skip( $checks, 'embed.defaults-deterministic', 'wp_embed_defaults unavailable' );
		}

		if ( function_exists( '_oembed_create_xml' ) && class_exists( 'SimpleXMLElement' ) && function_exists( 'wp_cache_get' ) && self::has_option_runtime() ) {
			$data = array(
				'type'    => 'rich',
				'version' => '1.0',
				'title'   => 'Example',
				'author'  => array( 'name' => 'Tester' ),
			);
			$call = self::call(
				'_oembed_create_xml:deterministic',
				static function () use ( $data ) {
					return array(
						\_oembed_create_xml( $data ),
						\_oembed_create_xml( $data ),
					);
				}
			);
			self::check_pair_determinism( $call, $checks, $failures, 'embed.create-xml-deterministic', '_oembed_create_xml' );
		} else {
			self::skip( $checks, 'embed.create-xml-deterministic', '_oembed_create_xml, SimpleXMLElement, cache API, or option runtime unavailable' );
		}

		if ( function_exists( 'wp_filter_oembed_iframe_title_attribute' ) && function_exists( 'wp_cache_get' ) && self::has_option_runtime() ) {
			$html = '<iframe src="https://example.test/embed" width="400" height="300"></iframe>';
			$data = (object) array(
				'type'  => 'video',
				'title' => 'Example title',
			);
			$call = self::call(
				'wp_filter_oembed_iframe_title_attribute',
				static function () use ( $html, $data ) {
					return array(
						\wp_filter_oembed_iframe_title_attribute( $html, $data, 'https://example.test/watch' ),
						\wp_filter_oembed_iframe_title_attribute( $html, $data, 'https://example.test/watch' ),
					);
				}
			);
			if ( ! $call['ok'] || ! is_array( $call['value'] ) || $call['value'][0] !== $call['value'][1] || false === strpos( $call['value'][0], 'title="Example title"' ) ) {
				self::fail(
					$checks,
					$failures,
					'embed.iframe-title-deterministic',
					'wp_filter_oembed_iframe_title_attribute did not add a deterministic title attribute.',
					array( 'call' => self::call_summary( $call ), 'valuePreview' => isset( $call['value'][0] ) ? self::preview( (string) $call['value'][0] ) : null )
				);
			} else {
				self::pass( $checks, 'embed.iframe-title-deterministic', array( 'outputSha1' => sha1( $call['value'][0] ), 'durationMs' => $call['durationMs'] ) );
			}
		} else {
			self::skip( $checks, 'embed.iframe-title-deterministic', 'wp_filter_oembed_iframe_title_attribute, cache API, or option runtime unavailable' );
		}

		self::check_embed_shortcode_handlers( $checks, $failures );
	}

	private static function load_wp_include( string $relative_path ): bool {
		$relative_path = ltrim( $relative_path, '/\\' );
		$candidates    = array();

		if ( defined( 'ABSPATH' ) ) {
			$candidates[] = rtrim( ABSPATH, '/\\' ) . DIRECTORY_SEPARATOR . $relative_path;
		}

		$candidates[] = dirname( __DIR__, 3 ) . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . $relative_path;
		$candidates   = array_values( array_unique( $candidates ) );

		foreach ( $candidates as $path ) {
			if ( is_string( $path ) && is_readable( $path ) ) {
				require_once $path;
				return true;
			}
		}

		return false;
	}

	private static function has_option_runtime(): bool {
		return function_exists( 'get_option' )
			&& isset( $GLOBALS['wpdb'] )
			&& is_object( $GLOBALS['wpdb'] )
			&& method_exists( $GLOBALS['wpdb'], 'suppress_errors' );
	}

	private static function check_embed_shortcode_handlers( array &$checks, array &$failures ): void {
		$ran = false;

		if ( function_exists( 'wp_embed_handler_audio' ) ) {
			$ran = true;
			$call = self::call(
				'wp_embed_handler_audio:deterministic',
				static function () {
					return array(
						\wp_embed_handler_audio( array(), array(), 'https://example.test/a.mp3?x=1', array() ),
						\wp_embed_handler_audio( array(), array(), 'https://example.test/a.mp3?x=1', array() ),
					);
				}
			);
			self::check_pair_determinism( $call, $checks, $failures, 'embed.audio-handler-deterministic', 'wp_embed_handler_audio' );
		}

		if ( function_exists( 'wp_embed_handler_video' ) ) {
			$ran = true;
			$call = self::call(
				'wp_embed_handler_video:deterministic',
				static function () {
					$raw = array( 'width' => 640, 'height' => 360 );
					return array(
						\wp_embed_handler_video( array(), array(), 'https://example.test/v.mp4?x=1', $raw ),
						\wp_embed_handler_video( array(), array(), 'https://example.test/v.mp4?x=1', $raw ),
					);
				}
			);
			self::check_pair_determinism( $call, $checks, $failures, 'embed.video-handler-deterministic', 'wp_embed_handler_video' );
		}

		if ( ! $ran ) {
			self::skip( $checks, 'embed.shortcode-handlers', 'audio/video embed handlers unavailable' );
		}
	}

	private static function check_pair_determinism( array $call, array &$checks, array &$failures, string $name, string $api ): void {
		if ( ! $call['ok'] || ! is_array( $call['value'] ) || count( $call['value'] ) < 2 || ! is_string( $call['value'][0] ) || ! is_string( $call['value'][1] ) ) {
			self::fail_from_call( $checks, $failures, $name, $call, $api . ' failed or returned an unexpected value.' );
			return;
		}

		if ( $call['value'][0] !== $call['value'][1] ) {
			self::fail(
				$checks,
				$failures,
				$name,
				$api . ' returned different output for identical inputs.',
				array(
					'firstSha1'       => sha1( $call['value'][0] ),
					'secondSha1'      => sha1( $call['value'][1] ),
					'firstDifference' => self::first_string_difference( $call['value'][0], $call['value'][1] ),
				)
			);
			return;
		}

		self::pass(
			$checks,
			$name,
			array(
				'outputLength' => strlen( $call['value'][0] ),
				'outputSha1'   => sha1( $call['value'][0] ),
				'durationMs'   => $call['durationMs'],
			)
		);
	}

	private static function anchor_attributes( string $html ): array {
		$processor = new \WP_HTML_Tag_Processor( $html );
		$anchors   = array();

		while ( $processor->next_tag( 'a' ) ) {
			$anchors[] = array(
				'href'     => self::attribute_value( $processor->get_attribute( 'href' ) ),
				'rel'      => self::attribute_value( $processor->get_attribute( 'rel' ) ),
				'target'   => self::attribute_value( $processor->get_attribute( 'target' ) ),
				'data-cfz' => self::attribute_value( $processor->get_attribute( 'data-cfz' ) ),
			);
		}

		return $anchors;
	}

	private static function attribute_value( $value ): ?string {
		if ( is_string( $value ) ) {
			return $value;
		}

		if ( true === $value ) {
			return '';
		}

		return null;
	}

	private static function rel_token_count( ?string $rel, string $token ): int {
		if ( null === $rel ) {
			return 0;
		}

		$tokens = preg_split( '/\s+/', trim( $rel ) );
		if ( ! is_array( $tokens ) ) {
			return 0;
		}

		return count( array_keys( $tokens, $token, true ) );
	}

	private static function external_fixture_host(): ?string {
		$internal_hosts = array_map(
			static function ( $host ): string {
				return strtolower( (string) $host );
			},
			(array) \wp_internal_hosts()
		);

		foreach ( array( 'component-fuzz-external.invalid', 'external.test', 'example.invalid' ) as $host ) {
			if ( ! in_array( $host, $internal_hosts, true ) ) {
				return $host;
			}
		}

		return null;
	}

	private static function generate_markup( array &$rng, int $max_bytes ): array {
		$profile = self::rng_weighted(
			$rng,
			array(
				'mixed'         => 28,
				'blocks'        => 24,
				'shortcodes'    => 18,
				'malformed'     => 14,
				'bracket-storm' => 9,
				'html'          => 7,
			)
		);

		$features = array( 'profile:' . $profile );

		if ( 'blocks' === $profile ) {
			$input = self::generate_block_document( $rng, self::rng_int( $rng, 2, 4 ), $features );
		} elseif ( 'shortcodes' === $profile ) {
			$input = self::generate_shortcode_document( $rng, $features );
		} elseif ( 'malformed' === $profile ) {
			$input = self::generate_malformed_document( $rng, $features );
		} elseif ( 'bracket-storm' === $profile ) {
			$input = self::generate_bracket_storm_document( $rng, $features );
		} elseif ( 'html' === $profile ) {
			$input = self::generate_html_fragment( $rng, $features );
		} else {
			$input = self::generate_block_document( $rng, self::rng_int( $rng, 1, 3 ), $features )
				. "\n"
				. self::generate_shortcode_document( $rng, $features )
				. "\n"
				. self::generate_html_fragment( $rng, $features );
		}

		$input = self::trim_bytes( $input, min( $max_bytes, self::MAX_GENERATED_BYTES ) );
		sort( $features );

		return array(
			'profile'   => $profile,
			'input'     => $input,
			'features'  => array_values( array_unique( $features ) ),
			'byteLength'=> strlen( $input ),
			'inputSha1' => sha1( $input ),
		);
	}

	private static function generate_block_document( array &$rng, int $depth, array &$features ): string {
		$parts = array();
		$count = self::rng_int( $rng, 1, 4 );
		for ( $i = 0; $i < $count; ++$i ) {
			if ( self::rng_chance( $rng, 18 ) ) {
				$parts[] = self::generate_malformed_block_comment( $rng, $features );
				continue;
			}
			$parts[] = self::generate_block( $rng, $depth, $features );
		}
		return implode( "\n", $parts );
	}

	private static function generate_block( array &$rng, int $depth, array &$features ): string {
		$names = array(
			'core/paragraph',
			'core/group',
			'core/columns',
			'core/column',
			'core/html',
			'core/quote',
			'core/block',
			'core/pattern',
			'my-plugin/card',
			'acme/reusable-ish',
		);
		$block_name = self::rng_choice( $rng, $names );
		$comment_name = 0 === strpos( $block_name, 'core/' ) && self::rng_chance( $rng, 70 ) ? substr( $block_name, 5 ) : $block_name;
		if ( 'core/block' === $block_name || 'core/pattern' === $block_name ) {
			$features[] = 'block:reusable-ish';
		}

		$attrs = self::generate_block_attrs( $rng, $block_name );
		$attr_json = empty( $attrs ) ? '' : ' ' . self::json( $attrs );

		if ( $depth <= 0 || self::rng_chance( $rng, 35 ) ) {
			$features[] = 'block:self-closing';
			return '<!-- wp:' . $comment_name . $attr_json . ' /-->';
		}

		$inner = self::generate_inner_html( $rng, $features );
		if ( self::rng_chance( $rng, 65 ) ) {
			$features[] = 'block:nested';
			$inner .= "\n" . self::generate_block( $rng, $depth - 1, $features ) . "\n" . self::generate_inner_html( $rng, $features );
		}

		return '<!-- wp:' . $comment_name . $attr_json . ' -->' . $inner . '<!-- /wp:' . $comment_name . ' -->';
	}

	private static function generate_block_attrs( array &$rng, string $block_name ): array {
		$attrs = array();
		if ( self::rng_chance( $rng, 65 ) ) {
			$attrs['align'] = self::rng_choice( $rng, array( 'wide', 'full', 'left', 'center' ) );
		}
		if ( self::rng_chance( $rng, 55 ) ) {
			$attrs['className'] = 'cfz-' . self::rng_int( $rng, 1, 999 );
		}
		if ( self::rng_chance( $rng, 35 ) ) {
			$attrs['meta'] = array(
				'seed'  => self::rng_int( $rng, 1, 100000 ),
				'label' => self::rng_choice( $rng, array( 'plain', 'quote " mark', 'lt < gt > amp &' ) ),
			);
		}
		if ( 'core/block' === $block_name || self::rng_chance( $rng, 12 ) ) {
			$attrs['ref'] = self::rng_int( $rng, 1, 5000 );
		}
		return $attrs;
	}

	private static function generate_inner_html( array &$rng, array &$features ): string {
		$pieces = array(
			'<p>Alpha <strong>bold</strong> ' . self::rng_int( $rng, 1, 99 ) . '</p>',
			'<div data-x="1"><em>Nested</em><br></div>',
			'[cfz_a alpha="one two"]short [cfz_b /][/cfz_a]',
			'<a href="https://example.test/path?x=1&amp;y=2">link</a>',
			'<sup data-fn="1" class="fn"><a href="#fn-1" id="fnref-1">1</a></sup>',
			'<span title="unterminated><b>text',
		);
		$value = self::rng_choice( $rng, $pieces );
		if ( false !== strpos( $value, '[cfz_' ) ) {
			$features[] = 'inner:shortcode';
		}
		if ( false !== strpos( $value, 'unterminated' ) ) {
			$features[] = 'inner:malformed-html';
		}
		return $value;
	}

	private static function generate_malformed_block_comment( array &$rng, array &$features ): string {
		$features[] = 'block:malformed-comment';
		$cases = array(
			'<!-- wp:paragraph {"align":"wide" } <p>missing delimiter',
			'<!-- wp:group {"bad":"unterminated} --><p>x</p><!-- /wp:group -->',
			'<!-- /wp:paragraph --><p>orphan closer</p>',
			'<!-- wp:core/block {"ref":' . self::rng_int( $rng, 1, 99 ) . '} /--><span>after</span>',
			'<!-- wp:my-plugin/card {"dash":"a--b"} --><p>x</p><!-- /wp:my-plugin/card -->',
		);
		return self::rng_choice( $rng, $cases );
	}

	private static function generate_shortcode_document( array &$rng, array &$features ): string {
		$features[] = 'shortcode:nested';
		$quoted = self::rng_choice( $rng, array( 'one two', 'a \\" quote', 'brackets [x]', 'slash \\\\ path' ) );
		$parts = array(
			'[cfz_a alpha="' . $quoted . '" beta=\'single quoted\' bare=value]',
			'inner [cfz_b x="[nested]" escaped="a\\/b" /] tail',
			'[/cfz_a]',
			'[[cfz_echo escaped]]',
			'[unknown attr="kept"]',
		);

		if ( self::rng_chance( $rng, 45 ) ) {
			$features[] = 'shortcode:bracket-storm';
			$parts[] = self::generate_bracket_storm_document( $rng, $features );
		}

		return implode( ' ', $parts );
	}

	private static function generate_malformed_document( array &$rng, array &$features ): string {
		$features[] = 'html:malformed';
		return self::generate_malformed_block_comment( $rng, $features )
			. "\n"
			. '<div><p><em>broken</div><custom-el data-x="1"><span title="x>y">'
			. "\n"
			. '[cfz_a alpha="unterminated] [cfz_b / [[[ /]';
	}

	private static function generate_bracket_storm_document( array &$rng, array &$features ): string {
		$features[] = 'shortcode:bracket-storm';
		$left = str_repeat( '[', self::rng_int( $rng, 16, 96 ) );
		$right = str_repeat( ']', self::rng_int( $rng, 16, 96 ) );
		$middle = str_repeat( '[cfz_a x="1"', self::rng_int( $rng, 2, 12 ) );
		return $left . $middle . str_repeat( '[/cfz_a]', self::rng_int( $rng, 1, 8 ) ) . $right;
	}

	private static function generate_html_fragment( array &$rng, array &$features ): string {
		$features[] = 'html:fragment';
		$tags = array(
			'<section><h2>Title</h2><p>Words &amp; entities</p></section>',
			'<script>ignored()</script><style>.x{color:red}</style><p>visible</p>',
			'<a href="https://example.test/a?b=1&amp;c=2">Example</a>',
			'<blockquote><iframe src="https://example.test/embed" width="400"></iframe></blockquote>',
			'<ul><li>one<li>two</ul>',
		);
		return self::rng_choice( $rng, $tags );
	}

	private static function bracket_storm( string $input ): string {
		$seed = substr( $input, 0, 256 );
		return str_repeat( '[', 128 )
			. '[cfz_a alpha="one"]'
			. $seed
			. str_repeat( '[/cfz_a][', 48 )
			. str_repeat( ']', 128 );
	}

	private static function blocks_contain_named_block( array $blocks ): bool {
		foreach ( $blocks as $block ) {
			if ( is_array( $block ) && ! empty( $block['blockName'] ) ) {
				return true;
			}
			if ( is_array( $block ) && ! empty( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) && self::blocks_contain_named_block( $block['innerBlocks'] ) ) {
				return true;
			}
		}
		return false;
	}

	private static function blocks_are_safe_for_do_blocks( array $blocks ): bool {
		$allowed_core = array(
			'core/paragraph',
			'core/heading',
			'core/html',
			'core/group',
			'core/columns',
			'core/column',
			'core/list',
			'core/image',
			'core/quote',
			'core/freeform',
			null,
		);

		foreach ( $blocks as $block ) {
			if ( ! is_array( $block ) ) {
				return false;
			}
			$name = $block['blockName'] ?? null;
			if ( is_string( $name ) && 0 === strpos( $name, 'core/' ) && ! in_array( $name, $allowed_core, true ) ) {
				return false;
			}
			if ( ! empty( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) && ! self::blocks_are_safe_for_do_blocks( $block['innerBlocks'] ) ) {
				return false;
			}
		}

		return true;
	}

	private static function block_tree_shape( array $blocks ): array {
		$shape = array();
		foreach ( $blocks as $block ) {
			if ( ! is_array( $block ) ) {
				$shape[] = array( 'invalid' => gettype( $block ) );
				continue;
			}

			$inner_content = array();
			foreach ( (array) ( $block['innerContent'] ?? array() ) as $chunk ) {
				$inner_content[] = is_string( $chunk ) ? array( 'textSha1' => sha1( $chunk ), 'length' => strlen( $chunk ) ) : null;
			}

			$shape[] = array(
				'blockName'    => $block['blockName'] ?? null,
				'attrs'        => self::sort_value( is_array( $block['attrs'] ?? null ) ? $block['attrs'] : array() ),
				'innerHTML'    => array(
					'length' => is_string( $block['innerHTML'] ?? null ) ? strlen( $block['innerHTML'] ) : null,
					'sha1'   => is_string( $block['innerHTML'] ?? null ) ? sha1( $block['innerHTML'] ) : null,
				),
				'innerContent' => $inner_content,
				'innerBlocks'  => self::block_tree_shape( is_array( $block['innerBlocks'] ?? null ) ? $block['innerBlocks'] : array() ),
			);
		}
		return $shape;
	}

	private static function call( string $label, callable $callback ): array {
		$errors = array();
		$value = null;
		$throwable = null;
		$start = microtime( true );

		set_error_handler(
			static function ( $errno, $errstr, $errfile = '', $errline = 0 ) use ( &$errors ) {
				$errors[] = array(
					'errno'   => $errno,
					'message' => $errstr,
					'file'    => is_string( $errfile ) ? basename( $errfile ) : '',
					'line'    => $errline,
				);
				return true;
			}
		);

		try {
			$value = $callback();
		} catch ( \Throwable $e ) {
			$throwable = $e;
		} finally {
			restore_error_handler();
		}

		$duration_ms = ( microtime( true ) - $start ) * 1000.0;

		return array(
			'label'      => $label,
			'ok'         => null === $throwable && empty( $errors ),
			'value'      => $value,
			'errors'     => $errors,
			'throwable'  => null === $throwable ? null : get_class( $throwable ),
			'message'    => null === $throwable ? null : $throwable->getMessage(),
			'durationMs' => $duration_ms,
		);
	}

	private static function pass( array &$checks, string $name, array $details = array() ): void {
		$checks[ $name ] = array_merge(
			array(
				'ok'     => true,
				'status' => 'passed',
			),
			$details
		);
	}

	private static function skip( array &$checks, string $name, string $reason ): void {
		$checks[ $name ] = array(
			'ok'     => true,
			'status' => 'skipped',
			'reason' => $reason,
		);
	}

	private static function fail( array &$checks, array &$failures, string $name, string $message, array $details ): void {
		$failure = array_merge(
			array(
				'name'    => $name,
				'message' => $message,
			),
			$details
		);
		$checks[ $name ] = array_merge(
			array(
				'ok'      => false,
				'status'  => 'failed',
				'message' => $message,
			),
			$details
		);
		$failures[] = $failure;
	}

	private static function fail_from_call( array &$checks, array &$failures, string $name, array $call, string $message ): void {
		self::fail(
			$checks,
			$failures,
			$name,
			$message,
			array(
				'call' => self::call_summary( $call ),
			)
		);
	}

	private static function call_summary( array $call ): array {
		$value = $call['value'] ?? null;
		$summary = array(
			'label'      => $call['label'] ?? null,
			'ok'         => $call['ok'] ?? false,
			'errors'     => $call['errors'] ?? array(),
			'throwable'  => $call['throwable'] ?? null,
			'message'    => $call['message'] ?? null,
			'durationMs' => $call['durationMs'] ?? null,
			'valueType'  => gettype( $value ),
		);

		if ( is_string( $value ) ) {
			$summary['valueLength'] = strlen( $value );
			$summary['valueSha1']   = sha1( $value );
		} elseif ( is_array( $value ) ) {
			$summary['valueCount'] = count( $value );
			$summary['valueSha1']  = sha1( self::json( self::sort_value( $value ) ) );
		}

		return $summary;
	}

	private static function seed_from_context( \ComponentFuzz\FuzzContext $ctx ): int {
		foreach ( array( 'seed', 'getSeed', 'iteration', 'getIteration' ) as $method ) {
			if ( method_exists( $ctx, $method ) ) {
				try {
					return self::normalize_seed( $ctx->$method() );
				} catch ( \Throwable $e ) {
					// Try the next shape.
				}
			}
		}

		if ( method_exists( $ctx, 'option' ) ) {
			foreach ( array( 'seed', 'iteration' ) as $key ) {
				try {
					$value = $ctx->option( $key, null );
					if ( null !== $value ) {
						return self::normalize_seed( $value );
					}
				} catch ( \Throwable $e ) {
					// Try the next shape.
				}
			}
		}

		if ( isset( $ctx->seed ) ) {
			return self::normalize_seed( $ctx->seed );
		}

		return 1;
	}

	private static function input_from_context( \ComponentFuzz\FuzzContext $ctx ): ?string {
		foreach ( array( 'input', 'getInput', 'payload', 'getPayload' ) as $method ) {
			if ( method_exists( $ctx, $method ) ) {
				try {
					$value = $ctx->$method();
					if ( is_string( $value ) ) {
						return $value;
					}
				} catch ( \Throwable $e ) {
					// Try the next shape.
				}
			}
		}

		if ( method_exists( $ctx, 'option' ) ) {
			foreach ( array( 'input', 'content', 'payload' ) as $key ) {
				try {
					$value = $ctx->option( $key, null );
					if ( is_string( $value ) ) {
						return $value;
					}
				} catch ( \Throwable $e ) {
					// Try the next shape.
				}
			}
		}

		return null;
	}

	private static function max_input_bytes_from_context( \ComponentFuzz\FuzzContext $ctx ): int {
		$default = self::MAX_GENERATED_BYTES;

		foreach ( array( 'maxInputBytes', 'getMaxInputBytes' ) as $method ) {
			if ( method_exists( $ctx, $method ) ) {
				try {
					$value = (int) $ctx->$method();
					return $value > 0 ? min( $value, 16384 ) : $default;
				} catch ( \Throwable $e ) {
					// Try the next shape.
				}
			}
		}

		if ( method_exists( $ctx, 'option' ) ) {
			try {
				$value = (int) $ctx->option( 'max-input-bytes', $default );
				return $value > 0 ? min( $value, 16384 ) : $default;
			} catch ( \Throwable $e ) {
				return $default;
			}
		}

		return $default;
	}

	private static function normalize_seed( $value ): int {
		if ( is_int( $value ) ) {
			return $value;
		}
		if ( is_numeric( $value ) ) {
			return (int) $value;
		}
		return (int) sprintf( '%u', crc32( (string) $value ) );
	}

	private static function rng_create( int $seed ): array {
		return array(
			'seed'    => (string) $seed,
			'counter' => 0,
			'buffer'  => '',
		);
	}

	private static function rng_bytes( array &$rng, int $length ): string {
		while ( strlen( $rng['buffer'] ) < $length ) {
			$rng['buffer'] .= hash( 'sha256', $rng['seed'] . ':' . $rng['counter'], true );
			++$rng['counter'];
		}

		$out = substr( $rng['buffer'], 0, $length );
		$rng['buffer'] = substr( $rng['buffer'], $length );
		return $out;
	}

	private static function rng_uint32( array &$rng ): int {
		$parts = unpack( 'Nvalue', self::rng_bytes( $rng, 4 ) );
		return (int) $parts['value'];
	}

	private static function rng_int( array &$rng, int $min, int $max ): int {
		if ( $max <= $min ) {
			return $min;
		}
		return $min + ( self::rng_uint32( $rng ) % ( $max - $min + 1 ) );
	}

	private static function rng_chance( array &$rng, int $numerator, int $denominator = 100 ): bool {
		return self::rng_int( $rng, 1, $denominator ) <= $numerator;
	}

	private static function rng_choice( array &$rng, array $values ) {
		return $values[ self::rng_int( $rng, 0, count( $values ) - 1 ) ];
	}

	private static function rng_weighted( array &$rng, array $weights ): string {
		$total = array_sum( $weights );
		$pick  = self::rng_int( $rng, 1, max( 1, (int) $total ) );
		foreach ( $weights as $value => $weight ) {
			$pick -= $weight;
			if ( $pick <= 0 ) {
				return (string) $value;
			}
		}
		foreach ( $weights as $value => $_weight ) {
			return (string) $value;
		}
		return 'mixed';
	}

	private static function sort_value( $value ) {
		if ( ! is_array( $value ) ) {
			return $value;
		}

		$is_list = array_keys( $value ) === range( 0, count( $value ) - 1 );
		foreach ( $value as $key => $item ) {
			$value[ $key ] = self::sort_value( $item );
		}
		if ( ! $is_list ) {
			ksort( $value );
		}
		return $value;
	}

	private static function first_value_difference( $left, $right ): array {
		$left_json  = self::json( self::sort_value( $left ) );
		$right_json = self::json( self::sort_value( $right ) );
		return self::first_string_difference( $left_json, $right_json );
	}

	private static function first_string_difference( string $left, string $right ): array {
		$limit = min( strlen( $left ), strlen( $right ) );
		$pos = 0;
		while ( $pos < $limit && $left[ $pos ] === $right[ $pos ] ) {
			++$pos;
		}

		return array(
			'offset' => $pos,
			'left'   => self::preview( substr( $left, max( 0, $pos - 40 ), 120 ) ),
			'right'  => self::preview( substr( $right, max( 0, $pos - 40 ), 120 ) ),
		);
	}

	private static function trim_bytes( string $input, int $max_bytes ): string {
		if ( $max_bytes <= 0 || strlen( $input ) <= $max_bytes ) {
			return $input;
		}
		return substr( $input, 0, $max_bytes );
	}

	private static function preview( string $input, int $limit = 180 ): string {
		$slice = substr( $input, 0, $limit );
		$json = json_encode( $slice, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE );
		if ( false === $json ) {
			$json = '"' . base64_encode( $slice ) . '"';
		}
		return strlen( $input ) > $limit ? $json . '...' : $json;
	}

	private static function json( $value ): string {
		$json = json_encode( $value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE );
		return false === $json ? 'null' : $json;
	}
}
