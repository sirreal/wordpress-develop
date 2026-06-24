<?php
namespace ComponentFuzz\Surfaces;

if ( ! class_exists( 'wpdb', false ) ) {
	require_once \ComponentFuzz\repo_root() . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'wp-includes' . DIRECTORY_SEPARATOR . 'class-wpdb.php';
}

/**
 * Fuzzes real wpdb SQL formatting/building APIs without opening a database connection.
 */
final class WpdbSqlSurface {
	public const NAME = 'wpdb-sql';

	private const PREPARE_CASES    = 18;
	private const IDENTIFIER_CASES = 16;
	private const LIKE_CASES       = 16;
	private const BUILDER_CASES    = 8;

	public static function run( \ComponentFuzz\FuzzContext $ctx ): array {
		if ( ! class_exists( 'wpdb', false ) ) {
			return array(
				$ctx->skip( 'bootstrap.real_wpdb_available', 'The real wpdb class is unavailable.' ),
			);
		}

		$snapshot = self::snapshot_globals();
		$rows     = array();
		$db       = new WpdbSqlNoConnectionWpdb();

		try {
			$GLOBALS['wpdb'] = $db;

			$rows[] = self::check_prepare_variadic_array_agreement( $ctx, $db, self::generate_prepare_cases( $ctx->fork( 'prepare' ) ) );
			$rows[] = self::check_identifier_placeholders_are_contained( $ctx, $db, self::generate_identifier_cases( $ctx->fork( 'identifier' ) ) );
			$rows[] = self::check_literal_percent_like_round_trip( $ctx, $db, self::generate_like_cases( $ctx->fork( 'like-percent' ) ) );
			$rows[] = self::check_esc_like_wildcard_protection( $ctx, $db, self::generate_like_cases( $ctx->fork( 'esc-like' ) ) );
			$rows[] = self::check_malformed_placeholders_fail_closed( $ctx, $db, self::malformed_prepare_cases( $ctx->fork( 'malformed' ) ) );
			$rows[] = self::check_sql_builders_are_capturable( $ctx, $db, self::generate_builder_cases( $ctx->fork( 'builders' ) ) );
			$rows[] = self::check_builder_null_formats_are_type_agnostic( $ctx, $db );
		} finally {
			self::restore_globals( $snapshot );
		}

		$rows[] = self::check_global_state_restored( $ctx, $snapshot, $db );

		return $rows;
	}

	private static function check_prepare_variadic_array_agreement( \ComponentFuzz\FuzzContext $ctx, WpdbSqlNoConnectionWpdb $db, array $cases ): array {
		$failures = array();

		foreach ( $cases as $case_index => $case ) {
			$variadic = self::call(
				'prepare-variadic',
				static function () use ( $db, $case ) {
					return $db->prepare( $case['query'], ...$case['args'] );
				}
			);
			$array_arg = self::call(
				'prepare-array',
				static function () use ( $db, $case ) {
					return $db->prepare( $case['query'], $case['args'] );
				}
			);

			if ( ! $variadic['ok'] || ! $array_arg['ok'] || ! is_string( $variadic['value'] ) || ! is_string( $array_arg['value'] ) ) {
				$failures[] = self::call_failure(
					'prepare-call-failed',
					'wpdb::prepare() failed or returned a non-string for a valid generated query.',
					array( $variadic, $array_arg ),
					$case_index,
					$case
				);
				continue;
			}

			$variadic_sql = self::final_sql( $db, $variadic['value'] );
			$array_sql    = self::final_sql( $db, $array_arg['value'] );
			$violations   = array();

			if ( $variadic_sql !== $array_sql ) {
				$violations[] = 'variadic-array-mismatch';
			}

			if ( self::count_generated_placeholders( $case['query'] ) !== count( $case['args'] ) ) {
				$violations[] = 'case-placeholder-count-mismatch';
			}

			if ( self::contains_placeholder_outside_literals( $variadic_sql ) ) {
				$violations[] = 'unresolved-placeholder-outside-literal';
			}

			foreach ( $case['identifiers'] as $identifier ) {
				$expected = self::quote_identifier_oracle( $identifier );
				if ( ! str_contains( $variadic_sql, $expected ) ) {
					$violations[] = 'identifier-not-quoted';
					break;
				}
			}

			foreach ( $case['strings'] as $string ) {
				if ( ! in_array( $string, self::sql_string_literals( $variadic_sql ), true ) ) {
					$violations[] = 'string-argument-not-quoted-or-round-tripped';
					break;
				}
			}

			foreach ( $case['numbers'] as $number ) {
				if ( ! preg_match( '/(?<![\'0-9])' . preg_quote( (string) $number, '/' ) . '(?![0-9\'])/', $variadic_sql ) ) {
					$violations[] = 'integer-argument-not-unquoted';
					break;
				}
			}

			if ( array() !== $violations ) {
				$failures[] = array(
					'name'       => 'prepare-agreement-violation',
					'message'    => 'Variadic and array wpdb::prepare() calls diverged or left an unsafe placeholder/type shape.',
					'caseIndex'  => $case_index,
					'query'      => $case['query'],
					'args'       => self::describe_args( $case['args'] ),
					'variadic'   => self::describe_sql( $variadic_sql ),
					'array'      => self::describe_sql( $array_sql ),
					'violations' => array_values( array_unique( $violations ) ),
				);
			}
		}

		return self::check_row(
			$ctx,
			'prepare.placeholder_count_type_and_array_variadic_agreement',
			$failures,
			array( 'cases' => count( $cases ) )
		);
	}

	private static function check_identifier_placeholders_are_contained( \ComponentFuzz\FuzzContext $ctx, WpdbSqlNoConnectionWpdb $db, array $cases ): array {
		$failures = array();

		foreach ( $cases as $case_index => $case ) {
			$call = self::call(
				'prepare-identifiers',
				static function () use ( $db, $case ) {
					return $db->prepare(
						'SELECT %i FROM %i WHERE %i = %s ORDER BY %i',
						$case['column'],
						$case['table'],
						$case['whereColumn'],
						$case['value'],
						$case['orderColumn']
					);
				}
			);

			if ( ! $call['ok'] || ! is_string( $call['value'] ) ) {
				$failures[] = self::call_failure(
					'identifier-prepare-call-failed',
					'wpdb::prepare() failed while preparing identifier placeholders.',
					array( $call ),
					$case_index,
					$case
				);
				continue;
			}

			$sql         = self::final_sql( $db, $call['value'] );
			$identifiers = array( $case['column'], $case['table'], $case['whereColumn'], $case['orderColumn'] );
			$expected    = array_map( array( self::class, 'quote_identifier_oracle' ), $identifiers );
			$quoted      = self::sql_backtick_identifiers( $sql );
			$outside     = self::sql_without_quoted_regions( $sql );
			$violations  = array();

			if ( count( $quoted ) !== count( $identifiers ) ) {
				$violations[] = 'unexpected-quoted-identifier-count';
			}

			foreach ( $expected as $expected_identifier ) {
				if ( ! str_contains( $sql, $expected_identifier ) ) {
					$violations[] = 'missing-expected-quoted-identifier';
					break;
				}
			}

			foreach ( $identifiers as $identifier ) {
				$marker = self::identifier_marker( $identifier );
				if ( '' !== $marker && str_contains( $outside, $marker ) ) {
					$violations[] = 'identifier-marker-outside-backticks';
					break;
				}
			}

			foreach ( array( ';', '--', '/*', '*/', '#', "\n" ) as $dangerous ) {
				if ( str_contains( $outside, $dangerous ) ) {
					$violations[] = 'identifier-dangerous-fragment-outside-backticks';
					break;
				}
			}

			if ( array() !== $violations ) {
				$failures[] = array(
					'name'       => 'identifier-containment-violation',
					'message'    => '%i allowed generated identifier material outside backtick-quoted identifier regions.',
					'caseIndex'  => $case_index,
					'input'      => self::describe_args( $identifiers ),
					'sql'        => self::describe_sql( $sql ),
					'quoted'     => $quoted,
					'outside'    => self::describe_sql( $outside ),
					'violations' => array_values( array_unique( $violations ) ),
				);
			}
		}

		return self::check_row(
			$ctx,
			'prepare.identifier_placeholder_containment',
			$failures,
			array( 'cases' => count( $cases ) )
		);
	}

	private static function check_literal_percent_like_round_trip( \ComponentFuzz\FuzzContext $ctx, WpdbSqlNoConnectionWpdb $db, array $cases ): array {
		$failures = array();

		foreach ( $cases as $case_index => $case ) {
			$like   = '%' . $db->esc_like( $case['needle'] ) . '%';
			$marker = 'literal-%s-%i-' . $case['marker'];
			$call   = self::call(
				'prepare-like-percent',
				static function () use ( $db, $case, $like, $marker ) {
					return $db->prepare(
						"SELECT * FROM %i WHERE %i LIKE %s AND pct = '100%%' AND marker = %s",
						$case['table'],
						$case['column'],
						$like,
						$marker
					);
				}
			);

			if ( ! $call['ok'] || ! is_string( $call['value'] ) ) {
				$failures[] = self::call_failure(
					'like-percent-prepare-call-failed',
					'wpdb::prepare() failed for a generated LIKE query.',
					array( $call ),
					$case_index,
					$case
				);
				continue;
			}

			$raw_sql    = $call['value'];
			$final_sql  = self::final_sql( $db, $raw_sql );
			$literals   = self::sql_string_literals( $final_sql );
			$violations = array();

			if ( str_contains( $raw_sql, '%' ) ) {
				$violations[] = 'raw-prepare-left-bare-percent';
			}

			if ( ! in_array( $like, $literals, true ) ) {
				$violations[] = 'like-literal-did-not-round-trip';
			}

			if ( ! in_array( '100%', $literals, true ) ) {
				$violations[] = 'literal-percent-did-not-round-trip';
			}

			if ( ! in_array( $marker, $literals, true ) ) {
				$violations[] = 'placeholder-looking-argument-did-not-round-trip';
			}

			if ( self::contains_placeholder_outside_literals( $final_sql ) ) {
				$violations[] = 'placeholder-outside-literal-after-finalization';
			}

			if ( array() !== $violations ) {
				$failures[] = array(
					'name'       => 'like-percent-roundtrip-violation',
					'message'    => 'Literal percent signs or placeholder-looking LIKE fragments failed to round-trip through wpdb::prepare().',
					'caseIndex'  => $case_index,
					'needle'     => self::describe_string( $case['needle'] ),
					'expected'   => self::describe_string( $like ),
					'rawSql'     => self::describe_sql( $raw_sql ),
					'finalSql'   => self::describe_sql( $final_sql ),
					'literals'   => array_map( array( self::class, 'describe_string' ), $literals ),
					'violations' => array_values( array_unique( $violations ) ),
				);
			}
		}

		return self::check_row(
			$ctx,
			'prepare.literal_percent_and_like_roundtrip',
			$failures,
			array( 'cases' => count( $cases ) )
		);
	}

	private static function check_esc_like_wildcard_protection( \ComponentFuzz\FuzzContext $ctx, WpdbSqlNoConnectionWpdb $db, array $cases ): array {
		$failures = array();

		foreach ( $cases as $case_index => $case ) {
			$escaped = $db->esc_like( $case['needle'] );
			$oracle  = self::esc_like_oracle( $case['needle'] );
			$like    = '%' . $escaped . '%';
			$call    = self::call(
				'prepare-esc-like',
				static function () use ( $db, $case, $like ) {
					return $db->prepare(
						'SELECT 1 FROM %i WHERE %i LIKE %s',
						$case['table'],
						$case['column'],
						$like
					);
				}
			);

			if ( ! $call['ok'] || ! is_string( $call['value'] ) ) {
				$failures[] = self::call_failure(
					'esc-like-prepare-call-failed',
					'wpdb::prepare() failed after esc_like() wildcard escaping.',
					array( $call ),
					$case_index,
					$case
				);
				continue;
			}

			$final_sql  = self::final_sql( $db, $call['value'] );
			$literals   = self::sql_string_literals( $final_sql );
			$violations = array();

			if ( $escaped !== $oracle ) {
				$violations[] = 'esc-like-oracle-mismatch';
			}

			if ( ! in_array( $like, $literals, true ) ) {
				$violations[] = 'escaped-like-literal-did-not-round-trip-through-prepare';
			}

			if ( self::has_unescaped_like_wildcard( $escaped ) ) {
				$violations[] = 'escaped-body-has-unescaped-wildcard';
			}

			if ( array() !== $violations ) {
				$failures[] = array(
					'name'       => 'esc-like-protection-violation',
					'message'    => 'esc_like() did not protect generated wildcard input before wpdb::prepare().',
					'caseIndex'  => $case_index,
					'input'      => self::describe_string( $case['needle'] ),
					'escaped'    => self::describe_string( $escaped ),
					'oracle'     => self::describe_string( $oracle ),
					'sql'        => self::describe_sql( $final_sql ),
					'literals'   => array_map( array( self::class, 'describe_string' ), $literals ),
					'violations' => array_values( array_unique( $violations ) ),
				);
			}
		}

		return self::check_row(
			$ctx,
			'esc_like.prepare_wildcard_protection',
			$failures,
			array( 'cases' => count( $cases ) )
		);
	}

	private static function check_malformed_placeholders_fail_closed( \ComponentFuzz\FuzzContext $ctx, WpdbSqlNoConnectionWpdb $db, array $cases ): array {
		$failures = array();

		foreach ( $cases as $case_index => $case ) {
			$call = self::call(
				'prepare-malformed',
				static function () use ( $db, $case ) {
					return $db->prepare( $case['query'], ...$case['args'] );
				}
			);

			$violations = array();
			if ( ! $call['ok'] ) {
				$violations[] = 'php-warning-or-throwable';
			}

			$value     = $call['ok'] ? $call['value'] : null;
			$final_sql = is_string( $value ) ? self::final_sql( $db, $value ) : $value;

			if ( 'empty-string' === $case['expect'] && '' !== $value ) {
				$violations[] = 'expected-empty-string';
			} elseif ( 'null' === $case['expect'] && null !== $value ) {
				$violations[] = 'expected-null';
			} elseif ( 'no-extra-arg-leak' === $case['expect'] && is_string( $final_sql ) ) {
				foreach ( $case['mustNotContain'] as $needle ) {
					if ( str_contains( $final_sql, $needle ) ) {
						$violations[] = 'extra-argument-leaked';
						break;
					}
				}
			} elseif ( 'literal-malformed-percent' === $case['expect'] && is_string( $final_sql ) ) {
				foreach ( $case['mustNotContain'] as $needle ) {
					if ( str_contains( $final_sql, $needle ) ) {
						$violations[] = 'malformed-placeholder-consumed-argument';
						break;
					}
				}
			} elseif ( 'unsupported-value-type-closed' === $case['expect'] && is_string( $final_sql ) ) {
				if ( ! str_contains( $final_sql, "''" ) || str_contains( $final_sql, 'stdClass' ) ) {
					$violations[] = 'unsupported-value-type-not-blanked';
				}
			}

			if ( is_string( $final_sql ) && self::contains_placeholder_outside_literals( $final_sql ) && 'literal-malformed-percent' !== $case['expect'] ) {
				$violations[] = 'unexpected-placeholder-outside-literal';
			}

			if ( array() !== $violations ) {
				$failures[] = array(
					'name'       => 'malformed-placeholder-violation',
					'message'    => 'Malformed or miscounted wpdb::prepare() input did not fail closed without warnings.',
					'caseIndex'  => $case_index,
					'caseName'   => $case['name'],
					'query'      => $case['query'],
					'args'       => self::describe_args( $case['args'] ),
					'actual'     => is_string( $final_sql ) ? self::describe_sql( $final_sql ) : $final_sql,
					'call'       => self::call_summary( $call ),
					'violations' => array_values( array_unique( $violations ) ),
				);
			}
		}

		return self::check_row(
			$ctx,
			'prepare.malformed_and_arg_mismatch_fail_closed',
			$failures,
			array( 'cases' => count( $cases ) )
		);
	}

	private static function check_sql_builders_are_capturable( \ComponentFuzz\FuzzContext $ctx, WpdbSqlNoConnectionWpdb $db, array $cases ): array {
		$failures = array();

		foreach ( $cases as $case_index => $case ) {
			$builder_calls = array(
				'insert'  => static function () use ( $db, $case ) {
					return $db->insert( $case['table'], $case['data'], $case['formats'] );
				},
				'replace' => static function () use ( $db, $case ) {
					return $db->replace( $case['table'], $case['data'], $case['formats'] );
				},
				'update'  => static function () use ( $db, $case ) {
					return $db->update( $case['table'], $case['updateData'], $case['where'], $case['updateFormats'], $case['whereFormats'] );
				},
				'delete'  => static function () use ( $db, $case ) {
					return $db->delete( $case['table'], $case['where'], $case['whereFormats'] );
				},
			);

			foreach ( $builder_calls as $method => $callback ) {
				$db->reset_capture();
				$call = self::call( 'builder-' . $method, $callback );

				if ( ! $call['ok'] || 1 !== $call['value'] ) {
					$failures[] = self::call_failure(
						'builder-call-failed',
						"wpdb::{$method}() failed to build a capturable query.",
						array( $call ),
						$case_index,
						array_merge( $case, array( 'method' => $method ) )
					);
					continue;
				}

				$captured = $db->captured_queries();
				$sql      = $captured[0] ?? '';
				$problems = self::builder_query_violations( $method, $sql, $case );

				if ( array() !== $problems ) {
					$failures[] = array(
						'name'       => 'builder-sql-shape-violation',
						'message'    => "wpdb::{$method}() produced SQL with unexpected table, columns, formats, or placeholders.",
						'caseIndex'  => $case_index,
						'method'     => $method,
						'case'       => self::describe_builder_case( $case ),
						'sql'        => self::describe_sql( $sql ),
						'violations' => $problems,
					);
				}
			}
		}

		return self::check_row(
			$ctx,
			'builders.insert_update_delete_replace_sql_shape',
			$failures,
			array(
				'cases'   => count( $cases ),
				'methods' => array( 'insert', 'replace', 'update', 'delete' ),
			)
		);
	}

	private static function check_builder_null_formats_are_type_agnostic( \ComponentFuzz\FuzzContext $ctx, WpdbSqlNoConnectionWpdb $db ): array {
		$failures = array();
		$case     = self::generate_builder_null_case( $ctx->fork( 'builder-nulls' ) );

		$builder_calls = array(
			'insert'  => static function () use ( $db, $case ) {
				return $db->insert( $case['table'], $case['data'], $case['formats'] );
			},
			'replace' => static function () use ( $db, $case ) {
				return $db->replace( $case['table'], $case['data'], $case['formats'] );
			},
			'update'  => static function () use ( $db, $case ) {
				return $db->update( $case['table'], $case['updateData'], $case['where'], $case['updateFormats'], $case['whereFormats'] );
			},
			'delete'  => static function () use ( $db, $case ) {
				return $db->delete( $case['table'], $case['where'], $case['whereFormats'] );
			},
		);

		foreach ( $builder_calls as $method => $callback ) {
			$db->reset_capture();
			$call = self::call( 'builder-null-' . $method, $callback );

			if ( ! $call['ok'] || 1 !== $call['value'] ) {
				$failures[] = self::call_failure(
					'builder-null-call-failed',
					"wpdb::{$method}() failed to build a capturable null-format query.",
					array( $call ),
					0,
					array_merge( $case, array( 'method' => $method ) )
				);
				continue;
			}

			$sql        = $db->captured_queries()[0] ?? '';
			$violations = self::builder_null_format_violations( $method, $sql, $case );
			if ( array() !== $violations ) {
				$failures[] = array(
					'name'       => 'builder-null-format-violation',
					'message'    => "wpdb::{$method}() did not render null values independently of string/integer/float formats.",
					'method'     => $method,
					'case'       => self::describe_builder_null_case( $case ),
					'sql'        => self::describe_sql( $sql ),
					'violations' => $violations,
				);
			}
		}

		return self::check_row(
			$ctx,
			'builders.null_values_ignore_string_integer_float_formats',
			$failures,
			array(
				'methods' => array_keys( $builder_calls ),
				'formats' => array( '%s', '%d', '%f' ),
			)
		);
	}

	private static function builder_query_violations( string $method, string $sql, array $case ): array {
		$violations = array();

		if ( '' === $sql ) {
			return array( 'query-not-captured' );
		}

		if ( preg_match( '/\{[a-f0-9]{64}\}/', $sql ) ) {
			$violations[] = 'placeholder-escape-token-left-in-captured-query';
		}

		if ( self::contains_placeholder_outside_literals( $sql ) ) {
			$violations[] = 'printf-placeholder-left-outside-literals';
		}

		$identifiers = self::sql_backtick_identifiers( $sql );
		$allowed     = array_merge(
			array( $case['table'] ),
			array_keys( $case['data'] ),
			array_keys( $case['updateData'] ),
			array_keys( $case['where'] )
		);
		foreach ( $identifiers as $identifier ) {
			if ( ! in_array( $identifier, $allowed, true ) ) {
				$violations[] = 'unexpected-backtick-identifier';
				break;
			}
		}

		if ( in_array( $method, array( 'insert', 'replace' ), true ) ) {
			$verb = 'insert' === $method ? 'INSERT' : 'REPLACE';
			if ( ! preg_match( '/^' . $verb . ' INTO `([^`]*)` \((.*)\) VALUES \((.*)\)$/s', $sql, $matches ) ) {
				return array_merge( $violations, array( 'unexpected-insert-replace-shape' ) );
			}

			if ( $matches[1] !== $case['table'] ) {
				$violations[] = 'unexpected-table';
			}

			$fields = self::sql_backtick_identifiers( $matches[2] );
			if ( $fields !== array_keys( $case['data'] ) ) {
				$violations[] = 'unexpected-insert-fields';
			}

			foreach ( $case['data'] as $field => $value ) {
				$format = $case['formatByField'][ $field ];
				if ( ! self::sql_contains_builder_value( $sql, $field, $value, $format, 'insert' ) ) {
					$violations[] = 'insert-value-format-mismatch';
					break;
				}
			}

			return array_values( array_unique( $violations ) );
		}

		if ( 'update' === $method ) {
			if ( ! preg_match( '/^UPDATE `([^`]*)` SET (.*) WHERE (.*)$/s', $sql, $matches ) ) {
				return array_merge( $violations, array( 'unexpected-update-shape' ) );
			}

			if ( $matches[1] !== $case['table'] ) {
				$violations[] = 'unexpected-table';
			}

			foreach ( $case['updateData'] as $field => $value ) {
				$format = $case['updateFormatByField'][ $field ];
				if ( ! self::sql_contains_builder_value( $matches[2], $field, $value, $format, 'assignment' ) ) {
					$violations[] = 'update-set-format-mismatch';
					break;
				}
			}

			foreach ( $case['where'] as $field => $value ) {
				$format = $case['whereFormatByField'][ $field ];
				if ( ! self::sql_contains_builder_value( $matches[3], $field, $value, $format, 'condition' ) ) {
					$violations[] = 'update-where-format-mismatch';
					break;
				}
			}

			return array_values( array_unique( $violations ) );
		}

		if ( 'delete' === $method ) {
			if ( ! preg_match( '/^DELETE FROM `([^`]*)` WHERE (.*)$/s', $sql, $matches ) ) {
				return array_merge( $violations, array( 'unexpected-delete-shape' ) );
			}

			if ( $matches[1] !== $case['table'] ) {
				$violations[] = 'unexpected-table';
			}

			foreach ( $case['where'] as $field => $value ) {
				$format = $case['whereFormatByField'][ $field ];
				if ( ! self::sql_contains_builder_value( $matches[2], $field, $value, $format, 'condition' ) ) {
					$violations[] = 'delete-where-format-mismatch';
					break;
				}
			}
		}

		return array_values( array_unique( $violations ) );
	}

	private static function builder_null_format_violations( string $method, string $sql, array $case ): array {
		$violations = array();

		if ( '' === $sql ) {
			return array( 'query-not-captured' );
		}

		if ( preg_match( '/\{[a-f0-9]{64}\}/', $sql ) ) {
			$violations[] = 'placeholder-escape-token-left-in-captured-query';
		}

		if ( self::contains_placeholder_outside_literals( $sql ) ) {
			$violations[] = 'printf-placeholder-left-outside-literals';
		}

		if ( in_array( $method, array( 'insert', 'replace' ), true ) ) {
			$verb = 'insert' === $method ? 'INSERT' : 'REPLACE';
			if ( ! preg_match( '/^' . $verb . ' INTO `([^`]*)` \((.*)\) VALUES \((.*)\)$/s', $sql, $matches ) ) {
				return array_merge( $violations, array( 'unexpected-insert-replace-shape' ) );
			}

			if ( $matches[1] !== $case['table'] ) {
				$violations[] = 'unexpected-table';
			}

			if ( self::sql_backtick_identifiers( $matches[2] ) !== array_keys( $case['data'] ) ) {
				$violations[] = 'unexpected-insert-fields';
			}

			$values = array_map( 'trim', explode( ',', $matches[3] ) );
			if ( array_fill( 0, count( $case['data'] ), 'NULL' ) !== $values ) {
				$violations[] = 'null-insert-values-not-all-null-literals';
			}

			return array_values( array_unique( $violations ) );
		}

		if ( 'update' === $method ) {
			if ( ! preg_match( '/^UPDATE `([^`]*)` SET (.*) WHERE (.*)$/s', $sql, $matches ) ) {
				return array_merge( $violations, array( 'unexpected-update-shape' ) );
			}

			if ( $matches[1] !== $case['table'] ) {
				$violations[] = 'unexpected-table';
			}

			$update_fields = array_keys( $case['updateData'] );
			$set_clauses   = self::comma_clauses( $matches[2] );
			if ( count( $update_fields ) !== count( $set_clauses ) ) {
				$violations[] = 'update-set-clause-count-mismatch';
			}

			if ( self::sql_backtick_identifiers( $matches[2] ) !== $update_fields ) {
				$violations[] = 'update-set-unexpected-identifiers';
			}

			foreach ( $update_fields as $index => $field ) {
				if ( ! isset( $set_clauses[ $index ] ) || ! self::is_null_assignment_clause( $set_clauses[ $index ], $field ) ) {
					$violations[] = 'update-set-clause-not-exact-null-assignment';
					break;
				}
			}

			$where_fields  = array_keys( $case['where'] );
			$where_clauses = self::and_clauses( $matches[3] );
			if ( count( $where_fields ) !== count( $where_clauses ) ) {
				$violations[] = 'update-where-clause-count-mismatch';
			}

			if ( self::sql_backtick_identifiers( $matches[3] ) !== $where_fields ) {
				$violations[] = 'update-where-unexpected-identifiers';
			}

			foreach ( $where_fields as $index => $field ) {
				if ( ! isset( $where_clauses[ $index ] ) || ! self::is_null_predicate_clause( $where_clauses[ $index ], $field ) ) {
					$violations[] = 'update-where-clause-not-exact-is-null';
					break;
				}
			}

			return array_values( array_unique( $violations ) );
		}

		if ( 'delete' === $method ) {
			if ( ! preg_match( '/^DELETE FROM `([^`]*)` WHERE (.*)$/s', $sql, $matches ) ) {
				return array_merge( $violations, array( 'unexpected-delete-shape' ) );
			}

			if ( $matches[1] !== $case['table'] ) {
				$violations[] = 'unexpected-table';
			}

			$where_fields  = array_keys( $case['where'] );
			$where_clauses = self::and_clauses( $matches[2] );
			if ( count( $where_fields ) !== count( $where_clauses ) ) {
				$violations[] = 'delete-where-clause-count-mismatch';
			}

			if ( self::sql_backtick_identifiers( $matches[2] ) !== $where_fields ) {
				$violations[] = 'delete-where-unexpected-identifiers';
			}

			foreach ( $where_fields as $index => $field ) {
				if ( ! isset( $where_clauses[ $index ] ) || ! self::is_null_predicate_clause( $where_clauses[ $index ], $field ) ) {
					$violations[] = 'delete-where-clause-not-exact-is-null';
					break;
				}
			}
		}

		return array_values( array_unique( $violations ) );
	}

	private static function comma_clauses( string $sql ): array {
		return array_map( 'trim', explode( ',', $sql ) );
	}

	private static function and_clauses( string $sql ): array {
		$clauses = preg_split( '/\s+AND\s+/i', trim( $sql ) );

		return is_array( $clauses ) ? array_map( 'trim', $clauses ) : array();
	}

	private static function is_null_assignment_clause( string $clause, string $field ): bool {
		return (bool) preg_match( '/^`' . preg_quote( $field, '/' ) . '`\s*=\s*NULL$/', trim( $clause ) );
	}

	private static function is_null_predicate_clause( string $clause, string $field ): bool {
		return (bool) preg_match( '/^`' . preg_quote( $field, '/' ) . '`\s+IS NULL$/', trim( $clause ) );
	}

	private static function sql_contains_builder_value( string $sql, string $field, $value, string $format, string $context ): bool {
		if ( null === $value ) {
			if ( 'insert' === $context ) {
				return (bool) preg_match( '/(?:^|[,(]\s*)NULL(?:\s*[,)]|$)/', $sql );
			}

			$operator = 'condition' === $context ? 'IS NULL' : '= NULL';
			return (bool) preg_match( '/`' . preg_quote( $field, '/' ) . '`\s+' . preg_quote( $operator, '/' ) . '(?:\b|$)/', $sql );
		}

		if ( 'insert' === $context ) {
			if ( '%s' === $format ) {
				return in_array( (string) $value, self::sql_string_literals( $sql ), true );
			}

			return (bool) preg_match( '/(?:^|[,(]\s*)' . self::number_pattern( $value, $format ) . '(?:\s*[,)]|$)/', $sql );
		}

		if ( '%s' === $format ) {
			if ( ! preg_match( '/`' . preg_quote( $field, '/' ) . '`\s*=\s*\'((?:\\\\.|\'\'|[^\'])*)\'/s', $sql, $matches ) ) {
				return false;
			}

			return self::sql_unescape_string_literal_body( $matches[1] ) === (string) $value;
		}

		return (bool) preg_match( '/`' . preg_quote( $field, '/' ) . '`\s*=\s*' . self::number_pattern( $value, $format ) . '(?:\b|$)/', $sql );
	}

	private static function number_pattern( $value, string $format ): string {
		if ( '%d' === $format ) {
			return preg_quote( (string) (int) $value, '/' );
		}

		return '-?[0-9]+(?:\.[0-9]+)?';
	}

	private static function check_global_state_restored( \ComponentFuzz\FuzzContext $ctx, array $snapshot, WpdbSqlNoConnectionWpdb $db ): array {
		$failures = array();

		foreach ( $snapshot as $key => $entry ) {
			$exists = array_key_exists( $key, $GLOBALS );
			if ( $exists !== $entry['exists'] ) {
				$failures[] = array(
					'name'     => 'global-existence-mismatch',
					'global'   => $key,
					'expected' => $entry['exists'],
					'actual'   => $exists,
				);
				continue;
			}

			if ( ! $exists ) {
				continue;
			}

			$actual_hash = self::stable_hash( self::summarize_global( $key, $GLOBALS[ $key ] ) );
			if ( $actual_hash !== $entry['hash'] ) {
				$failures[] = array(
					'name'     => 'global-value-mismatch',
					'global'   => $key,
					'expected' => $entry['hash'],
					'actual'   => $actual_hash,
				);
			}
		}

		if ( function_exists( 'has_filter' ) && false !== has_filter( 'query', array( $db, 'remove_placeholder_escape' ) ) ) {
			$failures[] = array(
				'name'    => 'temporary-wpdb-query-filter-left-installed',
				'message' => 'The no-connection wpdb instance was still registered on the query filter after restoration.',
			);
		}

		return self::check_row(
			$ctx,
			'global_state.restored_after_wpdb_sql_surface',
			$failures,
			array( 'trackedGlobals' => array_keys( $snapshot ) )
		);
	}

	private static function generate_prepare_cases( \ComponentFuzz\FuzzContext $ctx ): array {
		$cases = array();

		for ( $i = 0; $i < self::PREPARE_CASES; ++$i ) {
			$table        = self::safe_identifier( $ctx->fork( 'prepare-table-' . $i ), 'cfz_table_' . $i );
			$string_col   = self::safe_identifier( $ctx->fork( 'prepare-string-col-' . $i ), 'cfz_s_' . $i );
			$integer_col  = self::safe_identifier( $ctx->fork( 'prepare-int-col-' . $i ), 'cfz_d_' . $i );
			$float_col    = self::safe_identifier( $ctx->fork( 'prepare-float-col-' . $i ), 'cfz_f_' . $i );
			$string_value = self::sql_text( $ctx->fork( 'prepare-string-' . $i ) );
			$integer      = $ctx->fork( 'prepare-int-' . $i )->int( -100000, 100000 );
			$float        = $ctx->fork( 'prepare-float-' . $i )->int( -100000, 100000 ) / max( 1, $ctx->fork( 'prepare-float-div-' . $i )->int( 1, 999 ) );

			$cases[] = array(
				'query'       => 'SELECT * FROM %i WHERE %i = %s AND %i >= %d AND %i < %f',
				'args'        => array( $table, $string_col, $string_value, $integer_col, $integer, $float_col, $float ),
				'identifiers' => array( $table, $string_col, $integer_col, $float_col ),
				'strings'     => array( $string_value ),
				'numbers'     => array( $integer ),
			);
		}

		return $cases;
	}

	private static function generate_identifier_cases( \ComponentFuzz\FuzzContext $ctx ): array {
		$payloads = array(
			'plain',
			'a`b',
			'field.name',
			'name with space',
			"line\nbreak",
			'semi;colon',
			'comment--tail',
			'block/*tail*/',
			'hash#tail',
			'percent%s%i',
			'quote"single\'',
			'comma,other',
			'paren) OR 1=1',
			'select',
			'back``tick',
			'trail`',
		);

		$cases = array();
		for ( $i = 0; $i < self::IDENTIFIER_CASES; ++$i ) {
			$marker  = 'cfzid_' . $ctx->fork( 'identifier-marker-' . $i )->int( 1000, 9999 ) . '_' . $i;
			$payload = $payloads[ $i % count( $payloads ) ];

			$cases[] = array(
				'table'       => $marker . '_table_' . $payload,
				'column'      => $marker . '_column_' . strrev( $payload ),
				'whereColumn' => $marker . '_where_' . $payload . '`x',
				'orderColumn' => $marker . '_order_' . $payload . ' --',
				'value'       => self::sql_text( $ctx->fork( 'identifier-value-' . $i ) ),
			);
		}

		return $cases;
	}

	private static function generate_like_cases( \ComponentFuzz\FuzzContext $ctx ): array {
		$fixed = array(
			'plain',
			'100% match',
			'under_score',
			'back\\slash',
			'%_\\',
			"%s %d %i %% '%'",
			"quote ' and \\",
			"line\n%_\tend",
		);

		$cases = array();
		for ( $i = 0; $i < self::LIKE_CASES; ++$i ) {
			$needle = $fixed[ $i % count( $fixed ) ];
			if ( $i >= count( $fixed ) ) {
				$needle = self::sql_text( $ctx->fork( 'like-text-' . $i ) ) . $ctx->choice( array( '%', '_', '\\', '%s', '%%' ) );
			}

			$cases[] = array(
				'table'  => self::safe_identifier( $ctx->fork( 'like-table-' . $i ), 'cfz_like_table_' . $i ),
				'column' => self::safe_identifier( $ctx->fork( 'like-column-' . $i ), 'cfz_like_col_' . $i ),
				'needle' => $needle,
				'marker' => self::safe_identifier( $ctx->fork( 'like-marker-' . $i ), 'cfz_marker_' . $i ),
			);
		}

		return $cases;
	}

	private static function malformed_prepare_cases( \ComponentFuzz\FuzzContext $ctx ): array {
		$hostile = "cfz_extra_' OR 1=1 -- " . $ctx->int( 100, 999 );

		return array(
			array(
				'name'   => 'under-arg',
				'query'  => 'SELECT * FROM wp_posts WHERE post_title = %s AND ID = %d',
				'args'   => array( 'title-only' ),
				'expect' => 'empty-string',
			),
			array(
				'name'           => 'over-arg',
				'query'          => 'SELECT * FROM wp_posts WHERE post_title = %s',
				'args'           => array( 'kept', $hostile ),
				'expect'         => 'no-extra-arg-leak',
				'mustNotContain' => array( $hostile, ' OR 1=1 ' ),
			),
			array(
				'name'           => 'array-too-many-for-single-placeholder',
				'query'          => 'SELECT * FROM wp_posts WHERE post_title = %s',
				'args'           => array( array( 'kept', $hostile ) ),
				'expect'         => 'null',
				'mustNotContain' => array( $hostile ),
			),
			array(
				'name'           => 'malformed-percent-q',
				'query'          => 'SELECT * FROM wp_posts WHERE post_title = %q',
				'args'           => array( $hostile ),
				'expect'         => 'literal-malformed-percent',
				'mustNotContain' => array( $hostile, ' OR 1=1 ' ),
			),
			array(
				'name'   => 'dual-use-identifier-and-string',
				'query'  => 'SELECT %1$i FROM wp_posts WHERE post_title = %1$s',
				'args'   => array( 'post_title' ),
				'expect' => 'null',
			),
			array(
				'name'   => 'unsupported-object-value',
				'query'  => 'SELECT * FROM wp_posts WHERE post_title = %s',
				'args'   => array( (object) array( 'hostile' => $hostile ) ),
				'expect' => 'unsupported-value-type-closed',
			),
			array(
				'name'           => 'no-placeholder-with-arg',
				'query'          => 'SELECT 1',
				'args'           => array( $hostile ),
				'expect'         => 'no-extra-arg-leak',
				'mustNotContain' => array( $hostile, ' OR 1=1 ' ),
			),
		);
	}

	private static function generate_builder_cases( \ComponentFuzz\FuzzContext $ctx ): array {
		$cases = array();

		for ( $i = 0; $i < self::BUILDER_CASES; ++$i ) {
			$table = self::safe_identifier( $ctx->fork( 'builder-table-' . $i ), 'cfz_builder_table_' . $i );
			$data  = array(
				self::safe_identifier( $ctx->fork( 'builder-title-col-' . $i ), 'cfz_title_' . $i ) => self::sql_text( $ctx->fork( 'builder-title-' . $i ) ),
				self::safe_identifier( $ctx->fork( 'builder-count-col-' . $i ), 'cfz_count_' . $i ) => $ctx->fork( 'builder-count-' . $i )->int( -1000, 1000 ),
				self::safe_identifier( $ctx->fork( 'builder-ratio-col-' . $i ), 'cfz_ratio_' . $i ) => $ctx->fork( 'builder-ratio-' . $i )->int( -10000, 10000 ) / 100,
				self::safe_identifier( $ctx->fork( 'builder-null-col-' . $i ), 'cfz_null_' . $i )  => null,
			);
			$formats = array( '%s', '%d', '%f', '%s' );

			$update_data = array_slice( $data, 0, 3, true );
			$where       = array(
				self::safe_identifier( $ctx->fork( 'builder-id-col-' . $i ), 'cfz_id_' . $i )     => $ctx->fork( 'builder-id-' . $i )->int( 1, 9999 ),
				self::safe_identifier( $ctx->fork( 'builder-slug-col-' . $i ), 'cfz_slug_' . $i ) => self::safe_identifier( $ctx->fork( 'builder-slug-' . $i ), 'cfz_slug_value_' . $i ),
				self::safe_identifier( $ctx->fork( 'builder-gone-col-' . $i ), 'cfz_gone_' . $i ) => null,
			);

			$cases[] = array(
				'table'               => $table,
				'data'                => $data,
				'formats'             => $formats,
				'formatByField'       => array_combine( array_keys( $data ), $formats ),
				'updateData'          => $update_data,
				'updateFormats'       => array( '%s', '%d', '%f' ),
				'updateFormatByField' => array_combine( array_keys( $update_data ), array( '%s', '%d', '%f' ) ),
				'where'               => $where,
				'whereFormats'        => array( '%d', '%s', '%d' ),
				'whereFormatByField'  => array_combine( array_keys( $where ), array( '%d', '%s', '%d' ) ),
			);
		}

		return $cases;
	}

	private static function generate_builder_null_case( \ComponentFuzz\FuzzContext $ctx ): array {
		$table       = self::safe_identifier( $ctx->fork( 'null-table' ), 'cfz_builder_nulls' );
		$data        = array(
			self::safe_identifier( $ctx->fork( 'insert-string-col' ), 'cfz_null_insert_s' ) => null,
			self::safe_identifier( $ctx->fork( 'insert-int-col' ), 'cfz_null_insert_d' )    => null,
			self::safe_identifier( $ctx->fork( 'insert-float-col' ), 'cfz_null_insert_f' )  => null,
		);
		$update_data = array(
			self::safe_identifier( $ctx->fork( 'update-string-col' ), 'cfz_null_update_s' ) => null,
			self::safe_identifier( $ctx->fork( 'update-int-col' ), 'cfz_null_update_d' )    => null,
			self::safe_identifier( $ctx->fork( 'update-float-col' ), 'cfz_null_update_f' )  => null,
		);
		$where       = array(
			self::safe_identifier( $ctx->fork( 'where-string-col' ), 'cfz_null_where_s' ) => null,
			self::safe_identifier( $ctx->fork( 'where-int-col' ), 'cfz_null_where_d' )    => null,
			self::safe_identifier( $ctx->fork( 'where-float-col' ), 'cfz_null_where_f' )  => null,
		);

		return array(
			'table'         => $table,
			'data'          => $data,
			'formats'       => array( '%s', '%d', '%f' ),
			'updateData'    => $update_data,
			'updateFormats' => array( '%s', '%d', '%f' ),
			'where'         => $where,
			'whereFormats'  => array( '%s', '%d', '%f' ),
		);
	}

	private static function safe_identifier( \ComponentFuzz\FuzzContext $ctx, string $prefix ): string {
		$suffix = strtolower( preg_replace( '/[^A-Za-z0-9_]/', '_', $ctx->identifier( 4, 12 ) ) );
		$value  = strtolower( preg_replace( '/[^A-Za-z0-9_]/', '_', $prefix . '_' . $suffix ) );

		if ( ! preg_match( '/^[A-Za-z_]/', $value ) ) {
			$value = 'cfz_' . $value;
		}

		return substr( $value, 0, 48 );
	}

	private static function sql_text( \ComponentFuzz\FuzzContext $ctx ): string {
		$pieces = array(
			'plain',
			"quote ' double \" slash \\",
			'percent % and placeholder %s %d %i',
			'wildcards _ % \\',
			'comma, paren ) semi ;',
			"line\nbreak\tend",
			$ctx->ascii( 0, 24 ),
		);

		return substr( $ctx->choice( $pieces ) . ' cfz_' . $ctx->int( 1000, 9999 ), 0, 80 );
	}

	private static function count_generated_placeholders( string $query ): int {
		preg_match_all( '/(?<!%)%(?:[1-9][0-9]*[$])?[-+0-9]*(?: |0|\'.)?[-+0-9]*(?:\.[0-9]+)?[sdfFi]/', $query, $matches );

		return count( $matches[0] );
	}

	private static function quote_identifier_oracle( string $identifier ): string {
		return '`' . str_replace( '`', '``', $identifier ) . '`';
	}

	private static function identifier_marker( string $identifier ): string {
		if ( preg_match( '/(cfzid_[0-9]+_[0-9]+)/', $identifier, $matches ) ) {
			return $matches[1];
		}

		return '';
	}

	private static function esc_like_oracle( string $input ): string {
		$out = '';
		$len = strlen( $input );
		for ( $i = 0; $i < $len; ++$i ) {
			$char = $input[ $i ];
			if ( '_' === $char || '%' === $char || '\\' === $char ) {
				$out .= '\\';
			}
			$out .= $char;
		}

		return $out;
	}

	private static function has_unescaped_like_wildcard( string $like_body ): bool {
		$len = strlen( $like_body );
		for ( $i = 0; $i < $len; ++$i ) {
			if ( '_' !== $like_body[ $i ] && '%' !== $like_body[ $i ] ) {
				continue;
			}

			$slashes = 0;
			for ( $j = $i - 1; $j >= 0 && '\\' === $like_body[ $j ]; --$j ) {
				++$slashes;
			}

			if ( 0 === $slashes % 2 ) {
				return true;
			}
		}

		return false;
	}

	private static function final_sql( WpdbSqlNoConnectionWpdb $db, string $sql ): string {
		return $db->remove_placeholder_escape( $sql );
	}

	private static function contains_placeholder_outside_literals( string $sql ): bool {
		return (bool) preg_match( '/%(?:[1-9][0-9]*[$])?[-+0-9]*(?: |0|\'.)?[-+0-9]*(?:\.[0-9]+)?[sdfFi]/', self::sql_without_quoted_regions( $sql ) );
	}

	private static function sql_without_quoted_regions( string $sql ): string {
		$out             = '';
		$len             = strlen( $sql );
		$in_single_quote = false;
		$in_backtick     = false;

		for ( $i = 0; $i < $len; ++$i ) {
			$char = $sql[ $i ];

			if ( $in_single_quote ) {
				if ( '\\' === $char && $i + 1 < $len ) {
					$out .= '  ';
					++$i;
					continue;
				}

				if ( "'" === $char ) {
					if ( $i + 1 < $len && "'" === $sql[ $i + 1 ] ) {
						$out .= '  ';
						++$i;
						continue;
					}
					$in_single_quote = false;
				}

				$out .= ' ';
				continue;
			}

			if ( $in_backtick ) {
				if ( '`' === $char ) {
					if ( $i + 1 < $len && '`' === $sql[ $i + 1 ] ) {
						$out .= '  ';
						++$i;
						continue;
					}
					$in_backtick = false;
				}

				$out .= ' ';
				continue;
			}

			if ( "'" === $char ) {
				$in_single_quote = true;
				$out .= ' ';
				continue;
			}

			if ( '`' === $char ) {
				$in_backtick = true;
				$out .= ' ';
				continue;
			}

			$out .= $char;
		}

		return $out;
	}

	private static function sql_string_literals( string $sql ): array {
		$literals        = array();
		$len             = strlen( $sql );
		$in_single_quote = false;
		$body            = '';

		for ( $i = 0; $i < $len; ++$i ) {
			$char = $sql[ $i ];

			if ( ! $in_single_quote ) {
				if ( "'" === $char ) {
					$in_single_quote = true;
					$body            = '';
				}
				continue;
			}

			if ( '\\' === $char && $i + 1 < $len ) {
				$body .= $char . $sql[ $i + 1 ];
				++$i;
				continue;
			}

			if ( "'" === $char ) {
				if ( $i + 1 < $len && "'" === $sql[ $i + 1 ] ) {
					$body .= "''";
					++$i;
					continue;
				}

				$literals[]      = self::sql_unescape_string_literal_body( $body );
				$in_single_quote = false;
				$body            = '';
				continue;
			}

			$body .= $char;
		}

		return $literals;
	}

	private static function sql_unescape_string_literal_body( string $body ): string {
		$out = '';
		$len = strlen( $body );
		for ( $i = 0; $i < $len; ++$i ) {
			if ( '\\' === $body[ $i ] && $i + 1 < $len ) {
				$out .= $body[ $i + 1 ];
				++$i;
				continue;
			}

			if ( "'" === $body[ $i ] && $i + 1 < $len && "'" === $body[ $i + 1 ] ) {
				$out .= "'";
				++$i;
				continue;
			}

			$out .= $body[ $i ];
		}

		return $out;
	}

	private static function sql_backtick_identifiers( string $sql ): array {
		$identifiers     = array();
		$len             = strlen( $sql );
		$in_single_quote = false;
		$in_backtick     = false;
		$body            = '';

		for ( $i = 0; $i < $len; ++$i ) {
			$char = $sql[ $i ];

			if ( $in_single_quote ) {
				if ( '\\' === $char && $i + 1 < $len ) {
					++$i;
					continue;
				}
				if ( "'" === $char ) {
					if ( $i + 1 < $len && "'" === $sql[ $i + 1 ] ) {
						++$i;
						continue;
					}
					$in_single_quote = false;
				}
				continue;
			}

			if ( $in_backtick ) {
				if ( '`' === $char ) {
					if ( $i + 1 < $len && '`' === $sql[ $i + 1 ] ) {
						$body .= '`';
						++$i;
						continue;
					}

					$identifiers[] = $body;
					$body          = '';
					$in_backtick   = false;
					continue;
				}

				$body .= $char;
				continue;
			}

			if ( "'" === $char ) {
				$in_single_quote = true;
				continue;
			}

			if ( '`' === $char ) {
				$in_backtick = true;
			}
		}

		return $identifiers;
	}

	private static function snapshot_globals(): array {
		$snapshot = array();
		foreach ( array( 'wpdb', 'wp_filter', 'wp_actions', 'wp_filters', 'wp_current_filter', 'EZSQL_ERROR' ) as $key ) {
			$value = array_key_exists( $key, $GLOBALS ) ? self::snapshot_global_value( $key, $GLOBALS[ $key ] ) : null;

			$snapshot[ $key ] = array(
				'exists' => array_key_exists( $key, $GLOBALS ),
				'value'  => $value,
				'hash'   => self::stable_hash( self::summarize_global( $key, $value ) ),
			);
		}

		return $snapshot;
	}

	private static function restore_globals( array $snapshot ): void {
		foreach ( $snapshot as $key => $entry ) {
			if ( $entry['exists'] ) {
				$GLOBALS[ $key ] = self::snapshot_global_value( $key, $entry['value'] );
			} else {
				unset( $GLOBALS[ $key ] );
			}
		}
	}

	private static function snapshot_global_value( string $key, $value ) {
		if ( 'wp_filter' === $key ) {
			return self::clone_wp_filter( $value );
		}

		return $value;
	}

	private static function clone_wp_filter( $wp_filter ) {
		if ( ! is_array( $wp_filter ) ) {
			return $wp_filter;
		}

		$clone = array();
		foreach ( $wp_filter as $hook => $value ) {
			$clone[ $hook ] = is_object( $value ) ? clone $value : $value;
		}

		return $clone;
	}

	private static function summarize_global( string $key, $value ) {
		if ( 'wpdb' === $key ) {
			return is_object( $value ) ? spl_object_id( $value ) . ':' . get_class( $value ) : $value;
		}

		if ( 'wp_filter' === $key && is_array( $value ) ) {
			return array_keys( $value );
		}

		return $value;
	}

	private static function stable_hash( $value ): string {
		return hash( 'sha256', serialize( $value ) );
	}

	private static function check_row( \ComponentFuzz\FuzzContext $ctx, string $invariant, array $failures, array $data = array() ): array {
		$data['failureCount'] = count( $failures );
		if ( array() !== $failures ) {
			$data['failures'] = array_slice( $failures, 0, 8 );
		}

		return $ctx->result( $invariant, array() === $failures, $data );
	}

	private static function call( string $label, callable $callback ): array {
		try {
			return array(
				'ok'    => true,
				'label' => $label,
				'value' => $callback(),
			);
		} catch ( \Throwable $e ) {
			return array(
				'ok'        => false,
				'label'     => $label,
				'throwable' => self::describe_throwable( $e ),
			);
		}
	}

	private static function call_failure( string $name, string $message, array $calls, int $case_index, array $case ): array {
		return array(
			'name'      => $name,
			'message'   => $message,
			'caseIndex' => $case_index,
			'case'      => self::compact_case( $case ),
			'calls'     => array_map( array( self::class, 'call_summary' ), $calls ),
		);
	}

	private static function call_summary( array $call ): array {
		if ( ! $call['ok'] ) {
			return array(
				'ok'        => false,
				'label'     => $call['label'],
				'throwable' => $call['throwable'],
			);
		}

		return array(
			'ok'    => true,
			'label' => $call['label'],
			'type'  => gettype( $call['value'] ),
			'value' => self::describe_value( $call['value'] ),
		);
	}

	private static function compact_case( array $case ): array {
		unset( $case['formatByField'], $case['updateFormatByField'], $case['whereFormatByField'] );
		foreach ( $case as $key => $value ) {
			$case[ $key ] = self::describe_value( $value );
		}

		return $case;
	}

	private static function describe_builder_case( array $case ): array {
		return array(
			'table'         => $case['table'],
			'dataColumns'   => array_keys( $case['data'] ),
			'updateColumns' => array_keys( $case['updateData'] ),
			'whereColumns'  => array_keys( $case['where'] ),
			'formats'       => $case['formats'],
			'whereFormats'  => $case['whereFormats'],
		);
	}

	private static function describe_builder_null_case( array $case ): array {
		return array(
			'table'         => $case['table'],
			'dataColumns'   => array_keys( $case['data'] ),
			'updateColumns' => array_keys( $case['updateData'] ),
			'whereColumns'  => array_keys( $case['where'] ),
			'formats'       => $case['formats'],
			'updateFormats' => $case['updateFormats'],
			'whereFormats'  => $case['whereFormats'],
		);
	}

	private static function describe_args( array $args ): array {
		return array_map( array( self::class, 'describe_value' ), $args );
	}

	private static function describe_value( $value ) {
		if ( is_string( $value ) ) {
			return self::describe_string( $value );
		}

		if ( is_array( $value ) ) {
			return array_map( array( self::class, 'describe_value' ), $value );
		}

		if ( is_object( $value ) ) {
			return '[object ' . get_class( $value ) . ']';
		}

		return $value;
	}

	private static function describe_sql( string $sql ): string {
		return self::describe_string( $sql, 220 );
	}

	private static function describe_string( string $value, int $limit = 120 ): string {
		$printable = preg_replace_callback(
			'/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/',
			static function ( array $matches ): string {
				return sprintf( '\\x%02X', ord( $matches[0] ) );
			},
			$value
		);

		if ( strlen( $printable ) > $limit ) {
			return substr( $printable, 0, $limit ) . '...';
		}

		return $printable;
	}

	private static function describe_throwable( \Throwable $throwable ): array {
		return array(
			'class'   => get_class( $throwable ),
			'message' => $throwable->getMessage(),
			'file'    => basename( $throwable->getFile() ),
			'line'    => $throwable->getLine(),
		);
	}
}

final class WpdbSqlNoConnectionWpdb extends \wpdb {
	/** @var array<int,string> */
	private array $captured_queries = array();

	public function __construct() {
		$this->ready           = true;
		$this->suppress_errors = true;
		$this->show_errors     = false;
		$this->last_error      = '';
		$this->last_query      = '';
		$this->last_result     = array();
		$this->num_rows        = 0;
		$this->rows_affected   = 0;
		$this->insert_id       = 0;
		$this->prefix          = 'wp_';
		$this->base_prefix     = 'wp_';
		$this->charset         = 'utf8mb4';
		$this->collate         = 'utf8mb4_unicode_ci';

		foreach ( array( 'posts', 'comments', 'links', 'options', 'postmeta', 'terms', 'term_taxonomy', 'term_relationships', 'termmeta', 'commentmeta', 'users', 'usermeta' ) as $table ) {
			$this->{$table} = 'wp_' . $table;
		}
	}

	public function _real_escape( $data ) {
		if ( ! is_scalar( $data ) && null !== $data ) {
			return '';
		}

		return $this->add_placeholder_escape( addslashes( (string) $data ) );
	}

	public function get_col_charset( $table, $column ) {
		unset( $table, $column );
		return 'utf8mb4';
	}

	public function get_col_length( $table, $column ) {
		unset( $table, $column );
		return false;
	}

	protected function strip_invalid_text( $data ) {
		return $data;
	}

	public function query( $query ) {
		$query                  = $this->remove_placeholder_escape( (string) $query );
		$this->last_query       = $query;
		$this->captured_queries[] = $query;
		$this->rows_affected    = 1;
		$this->insert_id        = preg_match( '/^\s*(insert|replace)\s/i', $query ) ? $this->insert_id + 1 : $this->insert_id;

		return 1;
	}

	public function reset_capture(): void {
		$this->captured_queries = array();
		$this->last_query       = '';
		$this->last_error       = '';
		$this->rows_affected    = 0;
	}

	public function captured_queries(): array {
		return $this->captured_queries;
	}
}
