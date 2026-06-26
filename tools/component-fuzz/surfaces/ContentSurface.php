<?php
namespace ComponentFuzz\Surfaces;

final class ContentSurface {
	public const NAME = 'content';

	public static function run( \ComponentFuzz\FuzzContext $ctx ): array {
		$seed   = self::context_seed( $ctx );
		$rng    = self::rng( $seed );
		$inputs = self::generate_inputs( $rng );

		$result = array(
			'schemaVersion' => 1,
			'surface'       => self::NAME,
			'seed'          => $seed,
			'ok'            => true,
			'status'        => 'passed',
			'generated'     => self::generated_summary( $inputs ),
			'checks'        => array(),
			'failures'      => array(),
			'skipped'       => array(),
		);

		self::check_title_sanitizers( $result, $inputs );
		self::check_key_and_class_sanitizers( $result, $inputs );
		self::check_slashing_helpers( $result, $inputs );
		self::check_post_fields( $result, $inputs );
		self::check_sanitize_post_consistency( $result, $inputs );
		self::check_term_fields( $result, $inputs );
		self::check_metadata_serialization( $result, $inputs );
		self::check_extended_content_splitting( $result, $inputs );
		self::check_date_queries( $result, $inputs );
		self::check_query_var_normalization( $result, $inputs );
		self::check_get_post_class_splitting( $result, $inputs );
		self::check_post_template_password_excerpt_title( $result, $inputs );

		$result['ok']     = empty( $result['failures'] );
		$result['status'] = $result['ok'] ? 'passed' : 'failed';

		return $result;
	}

	private static function check_title_sanitizers( array &$result, array $inputs ): void {
		if ( ! \function_exists( 'sanitize_title' ) ) {
			self::skip( $result, 'sanitize_title', 'sanitize_title() is unavailable.' );
		} else {
			$failures = array();
			$cases    = 0;
			foreach ( $inputs['strings'] as $index => $title ) {
				foreach ( array( 'save', 'display', 'query' ) as $context ) {
					$fallback = 'fallback-' . $index;
					$first    = self::call_guarded(
						static function () use ( $title, $fallback, $context ) {
							return \sanitize_title( $title, $fallback, $context );
						}
					);
					++$cases;
					if ( ! $first['ok'] ) {
						$failures[] = self::call_failure( 'sanitize-title-throwable', 'sanitize_title() threw.', $first, array( 'context' => $context, 'input' => self::describe_string( $title ) ) );
						continue;
					}
					if ( ! is_string( $first['value'] ) ) {
						$failures[] = array(
							'name'    => 'sanitize-title-non-string',
							'message' => 'sanitize_title() returned a non-string value.',
							'context' => $context,
							'type'    => gettype( $first['value'] ),
						);
						continue;
					}

					$second = self::call_guarded(
						static function () use ( $first, $fallback, $context ) {
							return \sanitize_title( $first['value'], $fallback, $context );
						}
					);
					if ( ! $second['ok'] ) {
						$failures[] = self::call_failure( 'sanitize-title-repeat-throwable', 'sanitize_title() threw when called on its own output.', $second, array( 'context' => $context, 'input' => self::describe_string( $first['value'] ) ) );
						continue;
					}
					if ( $first['value'] !== $second['value'] ) {
						$failures[] = array(
							'name'       => 'sanitize-title-not-idempotent',
							'message'    => 'sanitize_title() changed an already-sanitized value.',
							'context'    => $context,
							'first'      => self::describe_string( $first['value'] ),
							'second'     => self::describe_string( (string) $second['value'] ),
							'difference' => self::first_string_difference( $first['value'], (string) $second['value'] ),
						);
					}
				}
			}
			self::record( $result, 'sanitize_title.idempotence', empty( $failures ), array( 'cases' => $cases ), $failures );
		}

		if ( ! \function_exists( 'sanitize_title_with_dashes' ) ) {
			self::skip( $result, 'sanitize_title_with_dashes', 'sanitize_title_with_dashes() is unavailable.' );
			return;
		}

		$failures = array();
		$cases    = 0;
		foreach ( $inputs['strings'] as $title ) {
			foreach ( array( 'display', 'save' ) as $context ) {
				$first = self::call_guarded(
					static function () use ( $title, $context ) {
						return \sanitize_title_with_dashes( $title, '', $context );
					}
				);
				++$cases;
				if ( ! $first['ok'] ) {
					$failures[] = self::call_failure( 'sanitize-title-with-dashes-throwable', 'sanitize_title_with_dashes() threw.', $first, array( 'context' => $context, 'input' => self::describe_string( $title ) ) );
					continue;
				}
				if ( ! is_string( $first['value'] ) ) {
					$failures[] = array(
						'name'    => 'sanitize-title-with-dashes-non-string',
						'message' => 'sanitize_title_with_dashes() returned a non-string value.',
						'context' => $context,
						'type'    => gettype( $first['value'] ),
					);
					continue;
				}
				if ( 1 !== preg_match( '/^[a-z0-9_%\-]*$/', $first['value'] ) ) {
					$failures[] = array(
						'name'    => 'sanitize-title-with-dashes-bad-characters',
						'message' => 'sanitize_title_with_dashes() returned characters outside the slug allow-list.',
						'context' => $context,
						'value'   => self::describe_string( $first['value'] ),
					);
				}

				$second = self::call_guarded(
					static function () use ( $first, $context ) {
						return \sanitize_title_with_dashes( $first['value'], '', $context );
					}
				);
				if ( ! $second['ok'] ) {
					$failures[] = self::call_failure( 'sanitize-title-with-dashes-repeat-throwable', 'sanitize_title_with_dashes() threw when called on its own output.', $second, array( 'context' => $context, 'input' => self::describe_string( $first['value'] ) ) );
					continue;
				}
				if ( $first['value'] !== $second['value'] ) {
					$failures[] = array(
						'name'       => 'sanitize-title-with-dashes-not-idempotent',
						'message'    => 'sanitize_title_with_dashes() changed an already-sanitized value.',
						'context'    => $context,
						'first'      => self::describe_string( $first['value'] ),
						'second'     => self::describe_string( (string) $second['value'] ),
						'difference' => self::first_string_difference( $first['value'], (string) $second['value'] ),
					);
				}
			}
		}
		self::record( $result, 'sanitize_title_with_dashes.allowlist_idempotence', empty( $failures ), array( 'cases' => $cases ), $failures );
	}

	private static function check_key_and_class_sanitizers( array &$result, array $inputs ): void {
		if ( ! \function_exists( 'sanitize_key' ) ) {
			self::skip( $result, 'sanitize_key', 'sanitize_key() is unavailable.' );
		} else {
			$failures = array();
			$cases    = 0;
			$values   = array_merge( $inputs['strings'], array( 0, 1, -7, true, false, null, array( 'not-scalar' ) ) );
			foreach ( $values as $value ) {
				$first = self::call_guarded(
					static function () use ( $value ) {
						return \sanitize_key( $value );
					}
				);
				++$cases;
				if ( ! $first['ok'] ) {
					$failures[] = self::call_failure( 'sanitize-key-throwable', 'sanitize_key() threw.', $first, array( 'inputType' => gettype( $value ) ) );
					continue;
				}
				if ( ! is_string( $first['value'] ) ) {
					$failures[] = array(
						'name'    => 'sanitize-key-non-string',
						'message' => 'sanitize_key() returned a non-string value.',
						'type'    => gettype( $first['value'] ),
					);
					continue;
				}
				if ( 1 !== preg_match( '/^[a-z0-9_-]*$/', $first['value'] ) ) {
					$failures[] = array(
						'name'    => 'sanitize-key-bad-characters',
						'message' => 'sanitize_key() returned characters outside the key allow-list.',
						'value'   => self::describe_string( $first['value'] ),
					);
				}

				$second = self::call_guarded(
					static function () use ( $first ) {
						return \sanitize_key( $first['value'] );
					}
				);
				if ( ! $second['ok'] ) {
					$failures[] = self::call_failure( 'sanitize-key-repeat-throwable', 'sanitize_key() threw when called on its own output.', $second );
					continue;
				}
				if ( $first['value'] !== $second['value'] ) {
					$failures[] = array(
						'name'       => 'sanitize-key-not-idempotent',
						'message'    => 'sanitize_key() changed an already-sanitized value.',
						'first'      => self::describe_string( $first['value'] ),
						'second'     => self::describe_string( (string) $second['value'] ),
						'difference' => self::first_string_difference( $first['value'], (string) $second['value'] ),
					);
				}
			}
			self::record( $result, 'sanitize_key.allowlist_idempotence', empty( $failures ), array( 'cases' => $cases ), $failures );
		}

		if ( ! \function_exists( 'sanitize_html_class' ) ) {
			self::skip( $result, 'sanitize_html_class', 'sanitize_html_class() is unavailable.' );
			return;
		}

		$failures = array();
		$cases    = 0;
		foreach ( $inputs['classStrings'] as $class_string ) {
			foreach ( preg_split( '/\s+/', $class_string ) ?: array( $class_string ) as $class ) {
				$fallback = 'fallback_class';
				$first    = self::call_guarded(
					static function () use ( $class, $fallback ) {
						return \sanitize_html_class( $class, $fallback );
					}
				);
				++$cases;
				if ( ! $first['ok'] ) {
					$failures[] = self::call_failure( 'sanitize-html-class-throwable', 'sanitize_html_class() threw.', $first, array( 'input' => self::describe_string( $class ) ) );
					continue;
				}
				if ( ! is_string( $first['value'] ) ) {
					$failures[] = array(
						'name'    => 'sanitize-html-class-non-string',
						'message' => 'sanitize_html_class() returned a non-string value.',
						'type'    => gettype( $first['value'] ),
					);
					continue;
				}
				if ( 1 !== preg_match( '/^[A-Za-z0-9_-]*$/', $first['value'] ) ) {
					$failures[] = array(
						'name'    => 'sanitize-html-class-bad-characters',
						'message' => 'sanitize_html_class() returned characters outside the class allow-list.',
						'value'   => self::describe_string( $first['value'] ),
					);
				}

				$second = self::call_guarded(
					static function () use ( $first, $fallback ) {
						return \sanitize_html_class( $first['value'], $fallback );
					}
				);
				if ( ! $second['ok'] ) {
					$failures[] = self::call_failure( 'sanitize-html-class-repeat-throwable', 'sanitize_html_class() threw when called on its own output.', $second );
					continue;
				}
				if ( $first['value'] !== $second['value'] ) {
					$failures[] = array(
						'name'       => 'sanitize-html-class-not-idempotent',
						'message'    => 'sanitize_html_class() changed an already-sanitized value.',
						'first'      => self::describe_string( $first['value'] ),
						'second'     => self::describe_string( (string) $second['value'] ),
						'difference' => self::first_string_difference( $first['value'], (string) $second['value'] ),
					);
				}
			}
		}
		self::record( $result, 'sanitize_html_class.allowlist_idempotence', empty( $failures ), array( 'cases' => $cases ), $failures );
	}

	private static function check_slashing_helpers( array &$result, array $inputs ): void {
		if ( ! \function_exists( 'wp_slash' ) || ! \function_exists( 'wp_unslash' ) ) {
			self::skip( $result, 'wp_slash_roundtrip', 'wp_slash() or wp_unslash() is unavailable.' );
			return;
		}

		$failures = array();
		$cases    = 0;
		foreach ( $inputs['posts'] as $post ) {
			$roundtrip = self::call_guarded(
				static function () use ( $post ) {
					return \wp_unslash( \wp_slash( $post ) );
				}
			);
			++$cases;
			if ( ! $roundtrip['ok'] ) {
				$failures[] = self::call_failure( 'wp-slash-roundtrip-throwable', 'wp_unslash( wp_slash( $post ) ) threw.', $roundtrip );
				continue;
			}
			if ( $post !== $roundtrip['value'] ) {
				$failures[] = array(
					'name'    => 'wp-slash-roundtrip-changed-value',
					'message' => 'wp_unslash( wp_slash( $post ) ) did not recover the original post-like array.',
					'post'    => self::describe_post( $post ),
				);
			}
		}
		foreach ( $inputs['strings'] as $string ) {
			$roundtrip = self::call_guarded(
				static function () use ( $string ) {
					return \wp_unslash( \wp_slash( $string ) );
				}
			);
			++$cases;
			if ( ! $roundtrip['ok'] ) {
				$failures[] = self::call_failure( 'wp-slash-string-roundtrip-throwable', 'wp_unslash( wp_slash( $string ) ) threw.', $roundtrip, array( 'input' => self::describe_string( $string ) ) );
				continue;
			}
			if ( $string !== $roundtrip['value'] ) {
				$failures[] = array(
					'name'       => 'wp-slash-string-roundtrip-changed-value',
					'message'    => 'wp_unslash( wp_slash( $string ) ) did not recover the original string.',
					'input'      => self::describe_string( $string ),
					'roundtrip'  => self::describe_string( (string) $roundtrip['value'] ),
					'difference' => self::first_string_difference( $string, (string) $roundtrip['value'] ),
				);
			}
		}
		self::record( $result, 'wp_slash.wp_unslash_roundtrip', empty( $failures ), array( 'cases' => $cases ), $failures );
	}

	private static function check_post_fields( array &$result, array $inputs ): void {
		if ( ! \function_exists( 'sanitize_post_field' ) ) {
			self::skip( $result, 'sanitize_post_field', 'sanitize_post_field() is unavailable.' );
			return;
		}

		$fields   = array( 'post_title', 'post_name', 'post_content', 'post_excerpt', 'comment_status', 'ping_status', 'post_type', 'post_status', 'post_password', 'ID', 'post_parent', 'menu_order' );
		$contexts = array( 'raw', 'db', 'edit', 'display', 'attribute', 'js' );
		$failures = array();
		$cases    = 0;

		foreach ( $inputs['posts'] as $post ) {
			$post_id = max( 0, (int) $post['ID'] );
			foreach ( $fields as $field ) {
				if ( ! array_key_exists( $field, $post ) ) {
					continue;
				}
				foreach ( $contexts as $context ) {
					$call = self::call_guarded(
						static function () use ( $field, $post, $post_id, $context ) {
							return \sanitize_post_field( $field, $post[ $field ], $post_id, $context );
						}
					);
					++$cases;
					if ( ! $call['ok'] ) {
						$failures[] = self::call_failure( 'sanitize-post-field-throwable', 'sanitize_post_field() threw.', $call, array( 'field' => $field, 'context' => $context ) );
						continue;
					}
					if ( ! is_scalar( $call['value'] ) && null !== $call['value'] ) {
						$failures[] = array(
							'name'    => 'sanitize-post-field-non-scalar',
							'message' => 'sanitize_post_field() returned a non-scalar value for a scalar field.',
							'field'   => $field,
							'context' => $context,
							'type'    => gettype( $call['value'] ),
						);
					}
					if ( 'raw' === $context ) {
						if ( in_array( $field, array( 'ID', 'post_parent', 'menu_order' ), true ) ) {
							if ( (int) $post[ $field ] !== $call['value'] ) {
								$failures[] = array(
									'name'    => 'sanitize-post-field-raw-int-cast-mismatch',
									'message' => 'sanitize_post_field() raw integer field did not match documented int cast.',
									'field'   => $field,
									'input'   => self::describe_scalar( $post[ $field ] ),
									'output'  => self::describe_scalar( $call['value'] ),
								);
							}
						} elseif ( $post[ $field ] !== $call['value'] ) {
							$failures[] = array(
								'name'    => 'sanitize-post-field-raw-changed-string',
								'message' => 'sanitize_post_field() raw context changed a non-integer field.',
								'field'   => $field,
								'input'   => self::describe_scalar( $post[ $field ] ),
								'output'  => self::describe_scalar( $call['value'] ),
							);
						}
					}
				}
			}

			if ( array_key_exists( 'ancestors', $post ) ) {
				$call = self::call_guarded(
					static function () use ( $post, $post_id ) {
						return \sanitize_post_field( 'ancestors', $post['ancestors'], $post_id, 'raw' );
					}
				);
				++$cases;
				if ( ! $call['ok'] ) {
					$failures[] = self::call_failure( 'sanitize-post-ancestors-throwable', 'sanitize_post_field() threw for ancestors.', $call );
				} elseif ( ! is_array( $call['value'] ) || ! self::array_values_are_non_negative_ints( $call['value'] ) ) {
					$failures[] = array(
						'name'    => 'sanitize-post-ancestors-not-non-negative-ints',
						'message' => 'sanitize_post_field() did not normalize ancestors to non-negative integers.',
						'output'  => self::describe_value( $call['value'] ),
					);
				}
			}
		}
		self::record( $result, 'sanitize_post_field.safe_contexts', empty( $failures ), array( 'cases' => $cases ), $failures );
	}

	private static function check_sanitize_post_consistency( array &$result, array $inputs ): void {
		if ( ! \function_exists( 'sanitize_post' ) || ! \function_exists( 'sanitize_post_field' ) ) {
			self::skip( $result, 'sanitize_post', 'sanitize_post() or sanitize_post_field() is unavailable.' );
			return;
		}

		$contexts         = array( 'raw', 'db', 'edit', 'display', 'attribute', 'js' );
		$post_cases       = self::sanitize_post_cases( $inputs['posts'] );
		$failures         = array();
		$cases            = 0;
		$field_cases      = 0;
		$hook_probe_cases = 0;
		$can_probe_hooks  = \function_exists( 'add_filter' ) && \function_exists( 'remove_filter' ) && \function_exists( 'current_filter' );
		$probe_fields     = array_unique(
			array_merge(
				self::sanitize_post_case_fields( $post_cases ),
				array(
					'filter',
					'post_author',
					'post_mime_type',
					'guid',
					'comment_count',
					'to_ping',
					'pinged',
					'component_fuzz_absent_field',
				)
			)
		);

		foreach ( $post_cases as $case ) {
			foreach ( $contexts as $context ) {
				$post        = $case['post'];
				$post_id     = array_key_exists( 'ID', $post ) ? (int) $post['ID'] : 0;
				$array_call  = self::call_guarded(
					static function () use ( $post, $context ) {
						return \sanitize_post( $post, $context );
					}
				);
				$object_post = (object) $post;
				$object_call = self::call_guarded(
					static function () use ( $object_post, $context ) {
						return \sanitize_post( $object_post, $context );
					}
				);
				++$cases;

				if ( ! $array_call['ok'] ) {
					$failures[] = self::call_failure(
						'sanitize-post-array-throwable',
						'sanitize_post() threw for an array post.',
						$array_call,
						array(
							'case'    => $case['label'],
							'context' => $context,
						)
					);
					continue;
				}
				if ( ! $object_call['ok'] ) {
					$failures[] = self::call_failure(
						'sanitize-post-object-throwable',
						'sanitize_post() threw for an object post.',
						$object_call,
						array(
							'case'    => $case['label'],
							'context' => $context,
						)
					);
					continue;
				}
				if ( ! is_array( $array_call['value'] ) ) {
					$failures[] = array(
						'name'    => 'sanitize-post-array-return-type',
						'message' => 'sanitize_post() did not preserve an array input type.',
						'case'    => $case['label'],
						'context' => $context,
						'type'    => gettype( $array_call['value'] ),
					);
					continue;
				}
				if ( ! is_object( $object_call['value'] ) ) {
					$failures[] = array(
						'name'    => 'sanitize-post-object-return-type',
						'message' => 'sanitize_post() did not preserve an object input type.',
						'case'    => $case['label'],
						'context' => $context,
						'type'    => gettype( $object_call['value'] ),
					);
					continue;
				}
				if ( $object_call['value'] !== $object_post ) {
					$failures[] = array(
						'name'    => 'sanitize-post-object-instance-changed',
						'message' => 'sanitize_post() returned a different object instance.',
						'case'    => $case['label'],
						'context' => $context,
					);
				}

				$object_as_array = get_object_vars( $object_call['value'] );
				if ( self::normalize_field_map_order( $array_call['value'] ) !== self::normalize_field_map_order( $object_as_array ) ) {
					$failures[] = array(
						'name'    => 'sanitize-post-array-object-mismatch',
						'message' => 'sanitize_post() produced different field values for equivalent array and object posts.',
						'case'    => $case['label'],
						'context' => $context,
						'array'   => self::describe_value( $array_call['value'] ),
						'object'  => self::describe_value( $object_as_array ),
					);
				}

				if ( ! array_key_exists( 'filter', $array_call['value'] ) || $context !== $array_call['value']['filter'] ) {
					$failures[] = array(
						'name'    => 'sanitize-post-array-filter-marker-mismatch',
						'message' => 'sanitize_post() did not stamp the array result with the requested filter context.',
						'case'    => $case['label'],
						'context' => $context,
						'filter'  => self::describe_value( $array_call['value']['filter'] ?? null ),
					);
				}
				if ( ! array_key_exists( 'filter', $object_as_array ) || $context !== $object_as_array['filter'] ) {
					$failures[] = array(
						'name'    => 'sanitize-post-object-filter-marker-mismatch',
						'message' => 'sanitize_post() did not stamp the object result with the requested filter context.',
						'case'    => $case['label'],
						'context' => $context,
						'filter'  => self::describe_value( $object_as_array['filter'] ?? null ),
					);
				}

				$expected_fields = $post;
				if ( ! array_key_exists( 'ID', $expected_fields ) ) {
					$expected_fields['ID'] = 0;
				}

				foreach ( $expected_fields as $field => $value ) {
					$field_call = self::call_guarded(
						static function () use ( $field, $value, $post_id, $context ) {
							return \sanitize_post_field( (string) $field, $value, $post_id, $context );
						}
					);
					++$field_cases;

					if ( ! $field_call['ok'] ) {
						$failures[] = self::call_failure(
							'sanitize-post-field-oracle-throwable',
							'sanitize_post_field() threw while building the sanitize_post() field oracle.',
							$field_call,
							array(
								'case'    => $case['label'],
								'context' => $context,
								'field'   => (string) $field,
							)
						);
						continue;
					}

					if ( ! array_key_exists( $field, $array_call['value'] ) || $field_call['value'] !== $array_call['value'][ $field ] ) {
						$failures[] = array(
							'name'     => 'sanitize-post-array-field-oracle-mismatch',
							'message'  => 'sanitize_post() array output did not match sanitize_post_field() for a generated field.',
							'case'     => $case['label'],
							'context'  => $context,
							'field'    => (string) $field,
							'expected' => self::describe_value( $field_call['value'] ),
							'actual'   => self::describe_value( $array_call['value'][ $field ] ?? null ),
						);
					}
					if ( ! array_key_exists( $field, $object_as_array ) || $field_call['value'] !== $object_as_array[ $field ] ) {
						$failures[] = array(
							'name'     => 'sanitize-post-object-field-oracle-mismatch',
							'message'  => 'sanitize_post() object output did not match sanitize_post_field() for a generated field.',
							'case'     => $case['label'],
							'context'  => $context,
							'field'    => (string) $field,
							'expected' => self::describe_value( $field_call['value'] ),
							'actual'   => self::describe_value( $object_as_array[ $field ] ?? null ),
						);
					}
				}

				if ( $can_probe_hooks ) {
					foreach ( array( 'array' => false, 'object' => true ) as $input_type => $as_object ) {
						$hook_probe = self::probe_sanitize_post_filter_hooks( $post, $context, $probe_fields, $as_object );
						++$hook_probe_cases;
						if ( ! $hook_probe['call']['ok'] ) {
							$failures[] = self::call_failure(
								'sanitize-post-filter-probe-throwable',
								'sanitize_post() threw during the filter locality probe.',
								$hook_probe['call'],
								array(
									'case'      => $case['label'],
									'context'   => $context,
									'inputType' => $input_type,
								)
							);
							continue;
						}

						$expected_hooks = self::sanitize_post_filter_hooks( array_keys( $expected_fields ), $context );
						$actual_hooks   = $hook_probe['hooks'];
						if ( $expected_hooks !== $actual_hooks ) {
							$failures[] = array(
								'name'      => 'sanitize-post-filter-locality-mismatch',
								'message'   => 'sanitize_post() did not invoke the expected field filter hooks for the generated fields and context.',
								'case'      => $case['label'],
								'context'   => $context,
								'inputType' => $input_type,
								'expected'  => $expected_hooks,
								'actual'    => $actual_hooks,
							);
						}
					}
				}
			}
		}

		if ( ! $can_probe_hooks ) {
			self::skip( $result, 'sanitize_post.filter_locality', 'WordPress filter helpers are unavailable.' );
		}

		self::record(
			$result,
			'sanitize_post.object_array_field_consistency_filter_locality',
			empty( $failures ),
			array(
				'cases'          => $cases,
				'fieldCases'     => $field_cases,
				'hookProbeCases' => $hook_probe_cases,
			),
			$failures
		);
	}

	private static function check_term_fields( array &$result, array $inputs ): void {
		if ( ! \function_exists( 'sanitize_term_field' ) ) {
			self::skip( $result, 'sanitize_term_field', 'sanitize_term_field() is unavailable.' );
			return;
		}

		$fields     = array( 'name', 'slug', 'description', 'parent', 'term_id', 'count', 'term_group', 'term_taxonomy_id', 'object_id' );
		$contexts   = array( 'raw', 'db', 'display', 'attribute', 'js' );
		$int_fields = array( 'parent', 'term_id', 'count', 'term_group', 'term_taxonomy_id', 'object_id' );
		$failures   = array();
		$cases      = 0;

		foreach ( $inputs['terms'] as $term ) {
			foreach ( $fields as $field ) {
				if ( ! array_key_exists( $field, $term ) ) {
					continue;
				}
				foreach ( $contexts as $context ) {
					$call = self::call_guarded(
						static function () use ( $field, $term, $context ) {
							return \sanitize_term_field( $field, $term[ $field ], (int) $term['term_id'], $term['taxonomy'], $context );
						}
					);
					++$cases;
					if ( ! $call['ok'] ) {
						$failures[] = self::call_failure( 'sanitize-term-field-throwable', 'sanitize_term_field() threw.', $call, array( 'field' => $field, 'context' => $context ) );
						continue;
					}
					if ( in_array( $field, $int_fields, true ) ) {
						if ( ! is_int( $call['value'] ) || $call['value'] < 0 ) {
							$failures[] = array(
								'name'    => 'sanitize-term-field-int-not-normalized',
								'message' => 'sanitize_term_field() did not normalize an integer field to a non-negative int.',
								'field'   => $field,
								'context' => $context,
								'output'  => self::describe_value( $call['value'] ),
							);
						}
					} elseif ( ! is_scalar( $call['value'] ) && null !== $call['value'] ) {
						$failures[] = array(
							'name'    => 'sanitize-term-field-non-scalar',
							'message' => 'sanitize_term_field() returned a non-scalar value for a scalar field.',
							'field'   => $field,
							'context' => $context,
							'type'    => gettype( $call['value'] ),
						);
					}
					if ( 'raw' === $context && ! in_array( $field, $int_fields, true ) && $term[ $field ] !== $call['value'] ) {
						$failures[] = array(
							'name'    => 'sanitize-term-field-raw-changed-string',
							'message' => 'sanitize_term_field() raw context changed a non-integer field.',
							'field'   => $field,
							'input'   => self::describe_scalar( $term[ $field ] ),
							'output'  => self::describe_scalar( $call['value'] ),
						);
					}
				}
			}
		}
		self::record( $result, 'sanitize_term_field.safe_contexts', empty( $failures ), array( 'cases' => $cases ), $failures );
	}

	private static function check_metadata_serialization( array &$result, array $inputs ): void {
		foreach ( array( 'maybe_serialize', 'maybe_unserialize', 'is_serialized' ) as $function ) {
			if ( ! \function_exists( $function ) ) {
				self::skip( $result, 'metadata_serialization', $function . '() is unavailable.' );
				return;
			}
		}

		$failures          = array();
		$roundtrip_cases   = 0;
		$serialize_only    = 0;
		$object_like_cases = 0;

		foreach ( $inputs['metadataValues'] as $value ) {
			$serialized = self::call_guarded(
				static function () use ( $value ) {
					return \maybe_serialize( $value );
				}
			);
			if ( ! $serialized['ok'] ) {
				$failures[] = self::call_failure( 'maybe-serialize-throwable', 'maybe_serialize() threw.', $serialized, array( 'input' => self::describe_value( $value ) ) );
				continue;
			}

			if ( ! self::supported_metadata_roundtrip_value( $value ) ) {
				++$serialize_only;
				continue;
			}

			if ( is_string( $serialized['value'] ) && self::serialized_payload_can_create_object( $serialized['value'] ) ) {
				$failures[] = array(
					'name'    => 'metadata-roundtrip-object-payload',
					'message' => 'A maybe_unserialize() round-trip case contained a serialized object-like token.',
					'payload' => self::describe_string( $serialized['value'] ),
				);
				continue;
			}

			$unserialized = self::call_guarded(
				static function () use ( $serialized ) {
					return \maybe_unserialize( $serialized['value'] );
				}
			);
			++$roundtrip_cases;
			if ( ! $unserialized['ok'] ) {
				$failures[] = self::call_failure( 'maybe-unserialize-throwable', 'maybe_unserialize() threw.', $unserialized, array( 'input' => self::describe_value( $serialized['value'] ) ) );
				continue;
			}
			if ( $value !== $unserialized['value'] ) {
				$failures[] = array(
					'name'       => 'metadata-roundtrip-mismatch',
					'message'    => 'maybe_unserialize( maybe_serialize( $value ) ) did not recover the original supported value.',
					'input'      => self::describe_value( $value ),
					'serialized' => self::describe_value( $serialized['value'] ),
					'roundtrip'  => self::describe_value( $unserialized['value'] ),
				);
			}
		}

		foreach ( $inputs['serializedObjectPayloads'] as $payload ) {
			++$object_like_cases;
			$probe = self::call_guarded(
				static function () use ( $payload ) {
					return \is_serialized( $payload, true );
				}
			);
			if ( ! $probe['ok'] ) {
				$failures[] = self::call_failure( 'is-serialized-object-payload-throwable', 'is_serialized() threw for an object-like payload.', $probe, array( 'payload' => self::describe_string( $payload ) ) );
			}
		}

		self::record(
			$result,
			'metadata.serialization_roundtrip_no_object_unserialize',
			empty( $failures ),
			array(
				'roundtripCases'            => $roundtrip_cases,
				'serializeOnlyCases'        => $serialize_only,
				'objectLikePayloadsSkipped' => $object_like_cases,
			),
			$failures
		);
	}

	private static function check_extended_content_splitting( array &$result, array $inputs ): void {
		if ( ! \function_exists( 'get_extended' ) ) {
			self::skip( $result, 'get_extended', 'get_extended() is unavailable.' );
			return;
		}

		$failures = array();
		$strings  = array_map(
			static function ( string $value ): string {
				return str_replace( array( "\r", "\n" ), ' ', $value );
			},
			$inputs['strings']
		);
		$cases    = array(
			array(
				'label'    => 'custom-more-text',
				'content'  => " \t" . $strings[0] . '<!--more Continue ' . substr( sha1( $strings[1] ), 0, 8 ) . ' -->' . $strings[2] . "\t ",
				'main'     => self::get_extended_component_trim( $strings[0] ),
				'extended' => self::get_extended_component_trim( $strings[2] . "\t " ),
				'moreText' => self::get_extended_component_trim( ' Continue ' . substr( sha1( $strings[1] ), 0, 8 ) . ' ' ),
			),
			array(
				'label'    => 'empty-more-text',
				'content'  => $strings[3] . '<!--more-->' . $strings[4],
				'main'     => self::get_extended_component_trim( $strings[3] ),
				'extended' => self::get_extended_component_trim( $strings[4] ),
				'moreText' => '',
			),
			array(
				'label'    => 'first-more-wins',
				'content'  => $strings[5] . '<!--more First-->' . $strings[6] . '<!--more Second-->' . $strings[7],
				'main'     => self::get_extended_component_trim( $strings[5] ),
				'extended' => self::get_extended_component_trim( $strings[6] . '<!--more Second-->' . $strings[7] ),
				'moreText' => self::get_extended_component_trim( ' First' ),
			),
			array(
				'label'    => 'space-before-more-is-not-a-marker',
				'content'  => $strings[8] . '<!-- more Not a marker-->' . $strings[9],
				'main'     => self::get_extended_component_trim( $strings[8] . '<!-- more Not a marker-->' . $strings[9] ),
				'extended' => '',
				'moreText' => '',
			),
		);

		foreach ( $cases as $case ) {
			$call = self::call_guarded(
				static function () use ( $case ) {
					return \get_extended( $case['content'] );
				}
			);
			if ( ! $call['ok'] ) {
				$failures[] = self::call_failure( 'get-extended-throwable', 'get_extended() threw.', $call, array( 'case' => $case['label'] ) );
				continue;
			}
			if ( ! is_array( $call['value'] ) ) {
				$failures[] = array(
					'name'    => 'get-extended-non-array',
					'message' => 'get_extended() returned a non-array value.',
					'case'    => $case['label'],
					'type'    => gettype( $call['value'] ),
				);
				continue;
			}

			$actual = $call['value'];
			if (
				array( 'main', 'extended', 'more_text' ) !== array_keys( $actual )
				|| $case['main'] !== $actual['main']
				|| $case['extended'] !== $actual['extended']
				|| $case['moreText'] !== $actual['more_text']
			) {
				$failures[] = array(
					'name'     => 'get-extended-split-mismatch',
					'message'  => 'get_extended() did not split the generated more tag as expected.',
					'case'     => $case['label'],
					'expected' => array(
						'main'      => self::describe_string( $case['main'] ),
						'extended'  => self::describe_string( $case['extended'] ),
						'moreText'  => self::describe_string( $case['moreText'] ),
					),
					'actual'   => self::describe_value( $actual ),
				);
			}
		}

		self::record( $result, 'get_extended.more_tag_splitting', empty( $failures ), array( 'cases' => count( $cases ) ), $failures );
	}

	private static function get_extended_component_trim( string $value ): string {
		$trimmed = preg_replace( '/^[\s]*(.*)[\s]*$/', '\\1', $value );
		return is_string( $trimmed ) ? $trimmed : $value;
	}

	private static function check_date_queries( array &$result, array $inputs ): void {
		if ( ! class_exists( '\WP_Date_Query' ) ) {
			self::skip( $result, 'WP_Date_Query', 'WP_Date_Query is unavailable.' );
			return;
		}
		if (
			! isset( $GLOBALS['wpdb'] )
			|| ! is_object( $GLOBALS['wpdb'] )
			|| ! method_exists( $GLOBALS['wpdb'], 'prepare' )
			|| ! \function_exists( 'esc_sql' )
			|| ! \function_exists( 'apply_filters' )
		) {
			self::skip( $result, 'WP_Date_Query', 'WP_Date_Query dependencies are unavailable.' );
			return;
		}

		$failures = array();
		$cases    = 0;
		foreach ( $inputs['dateQueries'] as $date_query ) {
			$call = self::call_guarded(
				static function () use ( $date_query ) {
					$query = new \WP_Date_Query( $date_query['query'], $date_query['defaultColumn'] );
					return $query->get_sql();
				}
			);
			++$cases;
			if ( ! $call['ok'] ) {
				$failures[] = self::call_failure( 'date-query-sql-throwable', 'WP_Date_Query SQL generation threw.', $call, array( 'case' => $date_query['label'] ) );
				continue;
			}
			if ( ! is_string( $call['value'] ) ) {
				$failures[] = array(
					'name'    => 'date-query-sql-non-string',
					'message' => 'WP_Date_Query::get_sql() returned a non-string value.',
					'case'    => $date_query['label'],
					'type'    => gettype( $call['value'] ),
				);
				continue;
			}
			if ( ! empty( $date_query['expectBalancedParentheses'] ) && ! self::balanced_parentheses( $call['value'] ) ) {
				$failures[] = array(
					'name'    => 'date-query-sql-unbalanced-parentheses',
					'message' => 'WP_Date_Query::get_sql() returned SQL with unbalanced parentheses for a valid-column query.',
					'case'    => $date_query['label'],
					'sql'     => self::describe_string( $call['value'] ),
				);
			}
		}
		self::record( $result, 'WP_Date_Query.sql_generation', empty( $failures ), array( 'cases' => $cases ), $failures );
	}

	private static function check_query_var_normalization( array &$result, array $inputs ): void {
		$failures = array();
		$cases    = 0;

		foreach ( $inputs['queryVars'] as $query_vars ) {
			$normalized = self::normalize_query_vars( $query_vars );
			++$cases;
			if ( ! self::query_vars_are_scalar_array_safe( $normalized ) ) {
				$failures[] = array(
					'name'    => 'query-vars-not-scalar-array-safe',
					'message' => 'Normalized query vars contained an object, resource, or unsafe key.',
					'value'   => self::describe_value( $normalized ),
				);
			}
		}

		if ( \function_exists( 'wp_parse_args' ) ) {
			foreach ( $inputs['queryArgStrings'] as $query_arg_string ) {
				$parsed = self::call_guarded(
					static function () use ( $query_arg_string ) {
						return \wp_parse_args( $query_arg_string );
					}
				);
				++$cases;
				if ( ! $parsed['ok'] ) {
					$failures[] = self::call_failure( 'wp-parse-args-throwable', 'wp_parse_args() threw.', $parsed, array( 'input' => self::describe_string( $query_arg_string ) ) );
					continue;
				}
				if ( ! is_array( $parsed['value'] ) ) {
					$failures[] = array(
						'name'    => 'wp-parse-args-non-array',
						'message' => 'wp_parse_args() returned a non-array value.',
						'type'    => gettype( $parsed['value'] ),
					);
					continue;
				}
				if ( ! self::query_vars_are_scalar_array_safe( self::normalize_query_vars( $parsed['value'] ) ) ) {
					$failures[] = array(
						'name'    => 'wp-parse-args-normalized-unsafe',
						'message' => 'wp_parse_args() output could not be normalized to scalar/array-safe query vars.',
						'input'   => self::describe_string( $query_arg_string ),
					);
				}
			}
		} else {
			self::skip( $result, 'wp_parse_args', 'wp_parse_args() is unavailable.' );
		}

		if ( \function_exists( 'wp_parse_id_list' ) ) {
			$id_list = self::call_guarded(
				static function () use ( $inputs ) {
					return \wp_parse_id_list( $inputs['idList'] );
				}
			);
			++$cases;
			if ( ! $id_list['ok'] ) {
				$failures[] = self::call_failure( 'wp-parse-id-list-throwable', 'wp_parse_id_list() threw.', $id_list );
			} elseif ( ! is_array( $id_list['value'] ) || ! self::array_values_are_non_negative_ints( $id_list['value'] ) ) {
				$failures[] = array(
					'name'    => 'wp-parse-id-list-not-non-negative-ints',
					'message' => 'wp_parse_id_list() did not return non-negative integers.',
					'output'  => self::describe_value( $id_list['value'] ),
				);
			}
		} else {
			self::skip( $result, 'wp_parse_id_list', 'wp_parse_id_list() is unavailable.' );
		}

		if ( \function_exists( 'wp_parse_slug_list' ) ) {
			$slug_list = self::call_guarded(
				static function () use ( $inputs ) {
					return \wp_parse_slug_list( $inputs['slugList'] );
				}
			);
			++$cases;
			if ( ! $slug_list['ok'] ) {
				$failures[] = self::call_failure( 'wp-parse-slug-list-throwable', 'wp_parse_slug_list() threw.', $slug_list );
			} elseif ( ! is_array( $slug_list['value'] ) ) {
				$failures[] = array(
					'name'    => 'wp-parse-slug-list-non-array',
					'message' => 'wp_parse_slug_list() returned a non-array value.',
					'type'    => gettype( $slug_list['value'] ),
				);
			} else {
				foreach ( $slug_list['value'] as $slug ) {
					if ( ! is_string( $slug ) ) {
						$failures[] = array(
							'name'    => 'wp-parse-slug-list-non-string-entry',
							'message' => 'wp_parse_slug_list() returned a non-string slug.',
							'type'    => gettype( $slug ),
						);
						continue;
					}
					if ( \function_exists( 'sanitize_title' ) ) {
						$again = self::call_guarded(
							static function () use ( $slug ) {
								return \sanitize_title( $slug );
							}
						);
						if ( ! $again['ok'] ) {
							$failures[] = self::call_failure( 'wp-parse-slug-list-resanitize-throwable', 'sanitize_title() threw for a wp_parse_slug_list() output slug.', $again, array( 'slug' => self::describe_string( $slug ) ) );
							continue;
						}
						if ( $slug !== $again['value'] ) {
							$failures[] = array(
								'name'       => 'wp-parse-slug-list-not-stable',
								'message'    => 'A wp_parse_slug_list() output slug was not stable through sanitize_title().',
								'slug'       => self::describe_string( $slug ),
								'sanitized'  => self::describe_string( (string) $again['value'] ),
								'difference' => self::first_string_difference( $slug, (string) $again['value'] ),
							);
						}
					}
				}
			}
		} else {
			self::skip( $result, 'wp_parse_slug_list', 'wp_parse_slug_list() is unavailable.' );
		}

		self::record( $result, 'query_var.normalization_scalar_array_safe', empty( $failures ), array( 'cases' => $cases ), $failures );
	}

	private static function check_get_post_class_splitting( array &$result, array $inputs ): void {
		if ( ! \function_exists( 'get_post_class' ) ) {
			self::skip( $result, 'get_post_class', 'get_post_class() is unavailable.' );
			return;
		}

		$failures = array();
		$cases    = 0;

		foreach ( $inputs['classStrings'] as $class_string ) {
			$had_global_post = array_key_exists( 'post', $GLOBALS );
			$global_post     = $had_global_post ? $GLOBALS['post'] : null;
			unset( $GLOBALS['post'] );

			$call = self::call_guarded(
				static function () use ( $class_string ) {
					return \get_post_class( $class_string, null );
				}
			);

			if ( $had_global_post ) {
				$GLOBALS['post'] = $global_post;
			} else {
				unset( $GLOBALS['post'] );
			}

			++$cases;
			if ( ! $call['ok'] ) {
				$failures[] = self::call_failure( 'get-post-class-throwable', 'get_post_class() threw while splitting classes without a post.', $call, array( 'input' => self::describe_string( $class_string ) ) );
				continue;
			}
			if ( ! is_array( $call['value'] ) ) {
				$failures[] = array(
					'name'    => 'get-post-class-non-array',
					'message' => 'get_post_class() returned a non-array value.',
					'type'    => gettype( $call['value'] ),
				);
				continue;
			}
			foreach ( $call['value'] as $class ) {
				if ( ! is_string( $class ) ) {
					$failures[] = array(
						'name'    => 'get-post-class-non-string-token',
						'message' => 'get_post_class() returned a non-string class token.',
						'type'    => gettype( $class ),
					);
					continue;
				}
				if ( 1 === preg_match( '/\s/', $class ) ) {
					$failures[] = array(
						'name'    => 'get-post-class-token-contains-whitespace',
						'message' => 'get_post_class() returned a class token containing whitespace.',
						'token'   => self::describe_string( $class ),
					);
				}
			}
		}

		self::record( $result, 'get_post_class.no_post_class_splitting', empty( $failures ), array( 'cases' => $cases ), $failures );
	}

	private static function check_post_template_password_excerpt_title( array &$result, array $inputs ): void {
		foreach ( array( 'get_the_title', 'get_the_excerpt', 'has_excerpt', 'post_password_required', 'add_filter', 'remove_filter', 'has_filter' ) as $function ) {
			if ( ! \function_exists( $function ) ) {
				self::skip( $result, 'post_template.password_excerpt_title', $function . '() is unavailable.' );
				return;
			}
		}
		if ( ! \class_exists( '\WP_Post' ) ) {
			self::skip( $result, 'post_template.password_excerpt_title', 'WP_Post is unavailable.' );
			return;
		}

		$cases            = self::post_template_cases( $inputs );
		$failures         = array();
		$events           = array();
		$password_probes  = 0;
		$excerpt_probes   = 0;
		$title_probes     = 0;
		$has_excerpt_runs = 0;
		$hash_skipped     = false;
		$cookie_name      = 'wp-postpass_' . ( \defined( 'COOKIEHASH' ) ? COOKIEHASH : '' );
		$had_cookie       = array_key_exists( $cookie_name, $_COOKIE );
		$cookie_value     = $had_cookie ? $_COOKIE[ $cookie_name ] : null;
		$had_screen       = array_key_exists( 'current_screen', $GLOBALS );
		$current_screen   = $had_screen ? $GLOBALS['current_screen'] : null;
		$excerpt_suffix   = ' [component-fuzz-excerpt]';
		$title_suffix     = ' [component-fuzz-title]';
		$protected_format = 'Protected fuzz: %s';
		$private_format   = 'Private fuzz: %s';

		$protected_filter = static function ( string $format, $post ) use ( &$events, $protected_format ): string {
			$events[] = array(
				'hook'   => 'protected_title_format',
				'postId' => isset( $post->ID ) ? (int) $post->ID : 0,
				'format' => $format,
			);
			return $protected_format;
		};
		$private_filter   = static function ( string $format, $post ) use ( &$events, $private_format ): string {
			$events[] = array(
				'hook'   => 'private_title_format',
				'postId' => isset( $post->ID ) ? (int) $post->ID : 0,
				'format' => $format,
			);
			return $private_format;
		};
		$title_filter     = static function ( string $title, int $post_id ) use ( &$events, $title_suffix ): string {
			$events[] = array(
				'hook'   => 'the_title',
				'postId' => $post_id,
				'title'  => $title,
			);
			return $title . $title_suffix . ':' . $post_id;
		};
		$excerpt_filter   = static function ( string $excerpt, $post ) use ( &$events, $excerpt_suffix ): string {
			$events[] = array(
				'hook'    => 'get_the_excerpt',
				'postId'  => isset( $post->ID ) ? (int) $post->ID : 0,
				'excerpt' => $excerpt,
			);
			return $excerpt . $excerpt_suffix;
		};
		$password_filter  = static function ( bool $required, $post ) use ( &$events ): bool {
			$events[] = array(
				'hook'     => 'post_password_required',
				'postId'   => isset( $post->ID ) ? (int) $post->ID : 0,
				'required' => $required,
			);
			return $required;
		};

		\add_filter( 'protected_title_format', $protected_filter, PHP_INT_MAX, 2 );
		\add_filter( 'private_title_format', $private_filter, PHP_INT_MAX, 2 );
		\add_filter( 'the_title', $title_filter, PHP_INT_MAX, 2 );
		\add_filter( 'get_the_excerpt', $excerpt_filter, PHP_INT_MAX, 2 );
		\add_filter( 'post_password_required', $password_filter, PHP_INT_MAX, 2 );

		try {
			unset( $GLOBALS['current_screen'] );

			foreach ( $cases as $case ) {
				$post         = self::make_wp_post( $case['post'] );
				$has_password = '' !== (string) $post->post_password;

				unset( $_COOKIE[ $cookie_name ] );
				$no_cookie_required = self::call_guarded(
					static function () use ( $post ) {
						return \post_password_required( $post );
					}
				);
				++$password_probes;
				self::assert_guarded_value(
					$failures,
					'post-password-required-no-cookie',
					'post_password_required() did not match the generated no-cookie oracle.',
					$no_cookie_required,
					$has_password,
					array(
						'case' => $case['label'],
						'post' => self::describe_template_post( $post ),
					)
				);

				$_COOKIE[ $cookie_name ] = 'not-a-wordpress-postpass-' . $case['token'];
				$bad_cookie_required     = self::call_guarded(
					static function () use ( $post ) {
						return \post_password_required( $post );
					}
				);
				++$password_probes;
				self::assert_guarded_value(
					$failures,
					'post-password-required-bad-cookie',
					'post_password_required() did not reject the generated malformed cookie.',
					$bad_cookie_required,
					$has_password,
					array(
						'case' => $case['label'],
						'post' => self::describe_template_post( $post ),
					)
				);

				if ( $has_password ) {
					$hash = self::postpass_hash( (string) $post->post_password );
					if ( null === $hash ) {
						$hash_skipped = true;
					} else {
						$_COOKIE[ $cookie_name ] = $hash;
						$good_cookie_required    = self::call_guarded(
							static function () use ( $post ) {
								return \post_password_required( $post );
							}
						);
						++$password_probes;
						self::assert_guarded_value(
							$failures,
							'post-password-required-good-cookie',
							'post_password_required() did not accept the generated phpass post cookie.',
							$good_cookie_required,
							false,
							array(
								'case'       => $case['label'],
								'post'       => self::describe_template_post( $post ),
								'hashPrefix' => substr( $hash, 0, 4 ),
							)
						);
					}
				}

				unset( $_COOKIE[ $cookie_name ] );
				$before_excerpt_events = self::event_count( $events, 'get_the_excerpt' );
				$excerpt_call          = self::call_guarded(
					static function () use ( $post ) {
						return \get_the_excerpt( $post );
					}
				);
				++$excerpt_probes;
				$expected_excerpt = $has_password ? \__( 'There is no excerpt because this is a protected post.' ) : (string) $post->post_excerpt . $excerpt_suffix;
				self::assert_guarded_value(
					$failures,
					'get-the-excerpt-password-branch',
					'get_the_excerpt() did not match the generated protected/public excerpt oracle.',
					$excerpt_call,
					$expected_excerpt,
					array(
						'case' => $case['label'],
						'post' => self::describe_template_post( $post ),
					)
				);
				$expected_excerpt_events = $has_password ? 0 : 1;
				$actual_excerpt_events   = self::event_count( $events, 'get_the_excerpt' ) - $before_excerpt_events;
				if ( $expected_excerpt_events !== $actual_excerpt_events ) {
					$failures[] = array(
						'name'     => 'get-the-excerpt-filter-count-mismatch',
						'message'  => 'get_the_excerpt() did not run the excerpt filter exactly in the expected password branch.',
						'case'     => $case['label'],
						'expected' => $expected_excerpt_events,
						'actual'   => $actual_excerpt_events,
					);
				}

				$has_excerpt_call = self::call_guarded(
					static function () use ( $post ) {
						return \has_excerpt( $post );
					}
				);
				++$has_excerpt_runs;
				self::assert_guarded_value(
					$failures,
					'has-excerpt-custom-excerpt',
					'has_excerpt() did not match the generated custom-excerpt oracle.',
					$has_excerpt_call,
					! empty( $post->post_excerpt ),
					array(
						'case' => $case['label'],
						'post' => self::describe_template_post( $post ),
					)
				);

				$before_protected_events = self::event_count( $events, 'protected_title_format' );
				$before_private_events   = self::event_count( $events, 'private_title_format' );
				$before_title_events     = self::event_count( $events, 'the_title' );
				$title_call              = self::call_guarded(
					static function () use ( $post ) {
						return \get_the_title( $post );
					}
				);
				++$title_probes;
				if ( $has_password ) {
					$expected_base_title = sprintf( $protected_format, (string) $post->post_title );
				} elseif ( 'private' === (string) $post->post_status ) {
					$expected_base_title = sprintf( $private_format, (string) $post->post_title );
				} else {
					$expected_base_title = (string) $post->post_title;
				}
				self::assert_guarded_value(
					$failures,
					'get-the-title-template-filters',
					'get_the_title() did not match the generated protected/private/title filter oracle.',
					$title_call,
					$expected_base_title . $title_suffix . ':' . (int) $post->ID,
					array(
						'case' => $case['label'],
						'post' => self::describe_template_post( $post ),
					)
				);

				$expected_protected_events = $has_password ? 1 : 0;
				$expected_private_events   = ( ! $has_password && 'private' === (string) $post->post_status ) ? 1 : 0;
				$actual_protected_events   = self::event_count( $events, 'protected_title_format' ) - $before_protected_events;
				$actual_private_events     = self::event_count( $events, 'private_title_format' ) - $before_private_events;
				$actual_title_events       = self::event_count( $events, 'the_title' ) - $before_title_events;
				if ( $expected_protected_events !== $actual_protected_events || $expected_private_events !== $actual_private_events || 1 !== $actual_title_events ) {
					$failures[] = array(
						'name'     => 'get-the-title-filter-count-mismatch',
						'message'  => 'get_the_title() did not run the expected protected/private/title filters exactly once.',
						'case'     => $case['label'],
						'expected' => array(
							'protected' => $expected_protected_events,
							'private'   => $expected_private_events,
							'title'     => 1,
						),
						'actual'   => array(
							'protected' => $actual_protected_events,
							'private'   => $actual_private_events,
							'title'     => $actual_title_events,
						),
					);
				}
			}
		} finally {
			\remove_filter( 'protected_title_format', $protected_filter, PHP_INT_MAX );
			\remove_filter( 'private_title_format', $private_filter, PHP_INT_MAX );
			\remove_filter( 'the_title', $title_filter, PHP_INT_MAX );
			\remove_filter( 'get_the_excerpt', $excerpt_filter, PHP_INT_MAX );
			\remove_filter( 'post_password_required', $password_filter, PHP_INT_MAX );

			if ( $had_cookie ) {
				$_COOKIE[ $cookie_name ] = $cookie_value;
			} else {
				unset( $_COOKIE[ $cookie_name ] );
			}
			if ( $had_screen ) {
				$GLOBALS['current_screen'] = $current_screen;
			} else {
				unset( $GLOBALS['current_screen'] );
			}
		}

		$leaked_hooks = array();
		foreach (
			array(
				array( 'protected_title_format', $protected_filter ),
				array( 'private_title_format', $private_filter ),
				array( 'the_title', $title_filter ),
				array( 'get_the_excerpt', $excerpt_filter ),
				array( 'post_password_required', $password_filter ),
			) as $hook_probe
		) {
			if ( false !== \has_filter( $hook_probe[0], $hook_probe[1] ) ) {
				$leaked_hooks[] = $hook_probe[0];
			}
		}
		if ( array() !== $leaked_hooks ) {
			$failures[] = array(
				'name'    => 'post-template-filter-leak',
				'message' => 'The post-template helper probe left one or more filters registered.',
				'hooks'   => $leaked_hooks,
			);
		}
		if ( $had_cookie ) {
			if ( ! array_key_exists( $cookie_name, $_COOKIE ) || $_COOKIE[ $cookie_name ] !== $cookie_value ) {
				$failures[] = array(
					'name'    => 'post-template-cookie-not-restored',
					'message' => 'The post-template helper probe did not restore the original post password cookie.',
				);
			}
		} elseif ( array_key_exists( $cookie_name, $_COOKIE ) ) {
			$failures[] = array(
				'name'    => 'post-template-cookie-leak',
				'message' => 'The post-template helper probe left a generated post password cookie behind.',
			);
		}

		if ( $hash_skipped ) {
			self::skip( $result, 'post_password_required.correct_cookie_hash', 'PasswordHash was unavailable for a correct post-password cookie branch.' );
		}

		self::record(
			$result,
			'post_template.password_excerpt_title_filters',
			empty( $failures ),
			array(
				'cases'           => count( $cases ),
				'passwordProbes'  => $password_probes,
				'excerptProbes'   => $excerpt_probes,
				'titleProbes'     => $title_probes,
				'hasExcerptRuns'  => $has_excerpt_runs,
				'eventCounts'     => array(
					'post_password_required' => self::event_count( $events, 'post_password_required' ),
					'get_the_excerpt'        => self::event_count( $events, 'get_the_excerpt' ),
					'protected_title_format' => self::event_count( $events, 'protected_title_format' ),
					'private_title_format'   => self::event_count( $events, 'private_title_format' ),
					'the_title'              => self::event_count( $events, 'the_title' ),
				),
				'cookieRestored'  => ! array_key_exists( $cookie_name, $_COOKIE ) || ( $had_cookie && $_COOKIE[ $cookie_name ] === $cookie_value ),
				'hashBranchCount' => $password_probes - ( 2 * count( $cases ) ),
			),
			$failures
		);
	}

	private static function post_template_cases( array $inputs ): array {
		$posts  = array_values( $inputs['posts'] );
		$cases  = array();
		$matrix = array(
			array(
				'label'    => 'public',
				'status'   => 'publish',
				'password' => '',
			),
			array(
				'label'    => 'private',
				'status'   => 'private',
				'password' => '',
			),
			array(
				'label'    => 'protected',
				'status'   => 'publish',
				'password' => 'component-fuzz-pass',
			),
			array(
				'label'    => 'private-protected',
				'status'   => 'private',
				'password' => 'component-fuzz-private-pass',
			),
		);

		foreach ( $matrix as $index => $row ) {
			$source = $posts[ $index % max( 1, count( $posts ) ) ] ?? array();
			$title  = self::non_empty_template_string( (string) ( $source['post_title'] ?? '' ), 'Template title ' . $index );
			$excerpt = self::non_empty_template_string(
				(string) ( $source['post_excerpt'] ?? '' ),
				'Template excerpt ' . $index
			);
			$content = self::non_empty_template_string(
				(string) ( $source['post_content'] ?? '' ),
				'Template content ' . $index
			);

			$post                    = $source;
			$post['ID']              = 51000 + $index;
			$post['post_title']      = $title;
			$post['post_excerpt']    = $excerpt;
			$post['post_content']    = $content;
			$post['post_status']     = $row['status'];
			$post['post_password']   = '' === $row['password'] ? '' : $row['password'] . '-' . substr( sha1( $title . ':' . $excerpt ), 0, 8 );
			$post['post_type']       = (string) ( $post['post_type'] ?? 'post' );
			$post['comment_status']  = (string) ( $post['comment_status'] ?? 'open' );
			$post['ping_status']     = (string) ( $post['ping_status'] ?? 'closed' );
			$post['post_name']       = (string) ( $post['post_name'] ?? 'template-title-' . $index );
			$post['post_parent']     = (int) ( $post['post_parent'] ?? 0 );
			$post['menu_order']      = (int) ( $post['menu_order'] ?? 0 );
			$post['filter']          = 'raw';
			$cases[]                 = array(
				'label' => $row['label'],
				'token' => substr( sha1( $row['label'] . ':' . $post['ID'] . ':' . $post['post_password'] ), 0, 12 ),
				'post'  => $post,
			);
		}

		return $cases;
	}

	private static function make_wp_post( array $post ): \WP_Post {
		$defaults = array(
			'ID'                    => 0,
			'post_author'           => '0',
			'post_date'             => '2024-01-01 00:00:00',
			'post_date_gmt'         => '2024-01-01 00:00:00',
			'post_content'          => '',
			'post_title'            => '',
			'post_excerpt'          => '',
			'post_status'           => 'publish',
			'comment_status'        => 'open',
			'ping_status'           => 'closed',
			'post_password'         => '',
			'post_name'             => '',
			'to_ping'               => '',
			'pinged'                => '',
			'post_modified'         => '2024-01-01 00:00:00',
			'post_modified_gmt'     => '2024-01-01 00:00:00',
			'post_content_filtered' => '',
			'post_parent'           => 0,
			'guid'                  => '',
			'menu_order'            => 0,
			'post_type'             => 'post',
			'post_mime_type'        => '',
			'comment_count'         => '0',
			'filter'                => 'raw',
		);

		return new \WP_Post( (object) array_merge( $defaults, $post, array( 'filter' => 'raw' ) ) );
	}

	private static function postpass_hash( string $password ): ?string {
		if ( ! \class_exists( '\PasswordHash' ) && \defined( 'ABSPATH' ) && \defined( 'WPINC' ) ) {
			require_once ABSPATH . WPINC . '/class-phpass.php';
		}
		if ( ! \class_exists( '\PasswordHash' ) ) {
			return null;
		}

		$hasher = new \PasswordHash( 8, true );
		return $hasher->HashPassword( $password );
	}

	private static function assert_guarded_value( array &$failures, string $name, string $message, array $call, $expected, array $context ): void {
		if ( ! $call['ok'] ) {
			$failures[] = self::call_failure( $name . '-throwable', $message, $call, $context );
			return;
		}

		if ( $expected !== $call['value'] ) {
			$failures[] = array_merge(
				array(
					'name'     => $name . '-mismatch',
					'message'  => $message,
					'expected' => self::describe_value( $expected ),
					'actual'   => self::describe_value( $call['value'] ),
				),
				$context
			);
		}
	}

	private static function event_count( array $events, string $hook ): int {
		$count = 0;
		foreach ( $events as $event ) {
			if ( $hook === ( $event['hook'] ?? null ) ) {
				++$count;
			}
		}

		return $count;
	}

	private static function non_empty_template_string( string $value, string $fallback ): string {
		return '' === $value ? $fallback : $value;
	}

	private static function describe_template_post( \WP_Post $post ): array {
		return array(
			'ID'          => (int) $post->ID,
			'postStatus'  => (string) $post->post_status,
			'hasPassword' => '' !== (string) $post->post_password,
			'title'       => self::describe_string( (string) $post->post_title ),
			'excerpt'     => self::describe_string( (string) $post->post_excerpt ),
		);
	}

	private static function sanitize_post_cases( array $posts ): array {
		$cases = array();
		foreach ( $posts as $index => $post ) {
			$cases[] = array(
				'label' => 'generated-' . $index,
				'post'  => $post,
			);
		}

		if ( ! empty( $posts ) ) {
			$missing_id = $posts[0];
			unset( $missing_id['ID'] );
			$cases[] = array(
				'label' => 'generated-missing-id',
				'post'  => $missing_id,
			);
		}

		return $cases;
	}

	private static function sanitize_post_case_fields( array $post_cases ): array {
		$fields = array();
		foreach ( $post_cases as $case ) {
			foreach ( array_keys( $case['post'] ) as $field ) {
				$fields[] = (string) $field;
			}
		}

		return array_values( array_unique( $fields ) );
	}

	private static function normalize_field_map_order( array $fields ): array {
		ksort( $fields );
		return $fields;
	}

	private static function probe_sanitize_post_filter_hooks( array $post, string $context, array $probe_fields, bool $as_object ): array {
		$observed = array();
		$callback = static function ( $value, ...$unused ) use ( &$observed ) {
			unset( $unused );
			$observed[] = \current_filter();
			return $value;
		};
		$hooks    = self::sanitize_post_observable_filter_hooks( $probe_fields );

		foreach ( $hooks as $hook ) {
			\add_filter( $hook, $callback, PHP_INT_MAX, 3 );
		}

		$post_input = $as_object ? (object) $post : $post;

		try {
			$call = self::call_guarded(
				static function () use ( $post_input, $context ) {
					return \sanitize_post( $post_input, $context );
				}
			);
		} finally {
			foreach ( $hooks as $hook ) {
				\remove_filter( $hook, $callback, PHP_INT_MAX );
			}
		}

		return array(
			'call'  => $call,
			'hooks' => $observed,
		);
	}

	private static function sanitize_post_filter_hooks( array $fields, string $context ): array {
		return self::sanitize_post_filter_hooks_for_fields( $fields, $context, false );
	}

	private static function sanitize_post_observable_filter_hooks( array $fields ): array {
		$hooks = array();
		foreach ( array( 'db', 'edit', 'display' ) as $context ) {
			$hooks = array_merge(
				$hooks,
				self::sanitize_post_filter_hooks_for_fields( $fields, $context, true )
			);
		}

		return array_values( array_unique( $hooks ) );
	}

	private static function sanitize_post_filter_hooks_for_fields( array $fields, string $context, bool $include_array_int_fields ): array {
		$hooks = array();
		foreach ( $fields as $field ) {
			$field = (string) $field;
			if ( 'raw' === $context || ( ! $include_array_int_fields && 'ancestors' === $field ) ) {
				continue;
			}

			$prefixed = str_contains( $field, 'post_' );
			if ( $prefixed ) {
				$field_no_prefix = str_replace( 'post_', '', $field );
			}

			if ( 'edit' === $context ) {
				if ( $prefixed ) {
					$hooks[] = "edit_{$field}";
					$hooks[] = "{$field_no_prefix}_edit_pre";
				} else {
					$hooks[] = "edit_post_{$field}";
				}
			} elseif ( 'db' === $context ) {
				if ( $prefixed ) {
					$hooks[] = "pre_{$field}";
					$hooks[] = "{$field_no_prefix}_save_pre";
				} else {
					$hooks[] = "pre_post_{$field}";
					$hooks[] = "{$field}_pre";
				}
			} elseif ( $prefixed ) {
				$hooks[] = $field;
			} else {
				$hooks[] = "post_{$field}";
			}
		}

		return $hooks;
	}

	private static function generate_inputs( array &$rng ): array {
		$strings = self::string_payloads( $rng );
		$posts   = array();
		$terms   = array();

		for ( $i = 0; $i < 4; ++$i ) {
			$title          = $strings[ self::rng_int( $rng, 0, count( $strings ) - 1 ) ];
			$slug           = $strings[ self::rng_int( $rng, 0, count( $strings ) - 1 ) ];
			$content        = $strings[ self::rng_int( $rng, 0, count( $strings ) - 1 ) ] . "\n<!--more-->\n" . $strings[ self::rng_int( $rng, 0, count( $strings ) - 1 ) ];
			$excerpt        = $strings[ self::rng_int( $rng, 0, count( $strings ) - 1 ) ];
			$comment_status = self::rng_choice( $rng, array( 'open', 'closed', 'registered_only', 'OPEN', '', "open\0closed" ) );
			$post_type      = self::sanitize_key_fallback( self::rng_choice( $rng, array( 'post', 'page', 'attachment', 'custom-type', 'Bad Type!' ) ) );
			if ( '' === $post_type ) {
				$post_type = 'post';
			}

			$posts[] = array(
				'ID'                    => self::rng_int( $rng, -5, 5000 ),
				'post_title'            => $title,
				'post_name'             => $slug,
				'post_content'          => $content,
				'post_excerpt'          => $excerpt,
				'post_status'           => self::rng_choice( $rng, array( 'publish', 'draft', 'future', 'private', 'bad status', '' ) ),
				'post_password'         => $strings[ self::rng_int( $rng, 0, count( $strings ) - 1 ) ],
				'post_type'             => $post_type,
				'post_parent'           => self::rng_int( $rng, -10, 250 ),
				'menu_order'            => self::rng_int( $rng, -20, 20 ),
				'comment_status'        => $comment_status,
				'ping_status'           => self::rng_choice( $rng, array( 'open', 'closed', 'OPEN', '', "closed\topen" ) ),
				'post_content_filtered' => '',
				'ancestors'             => array( '1', 2, '-3', 'bad', self::rng_int( $rng, -9, 9 ) ),
			);

			$taxonomy = self::sanitize_key_fallback( self::rng_choice( $rng, array( 'category', 'post_tag', 'genre', 'bad taxonomy!', '' ) ) );
			if ( '' === $taxonomy ) {
				$taxonomy = 'category';
			}
			$terms[] = array(
				'term_id'          => self::rng_int( $rng, -4, 2000 ),
				'name'             => $title,
				'slug'             => $slug,
				'description'      => $excerpt,
				'parent'           => self::rng_int( $rng, -5, 30 ),
				'count'            => self::rng_int( $rng, -10, 100 ),
				'term_group'       => self::rng_int( $rng, -3, 9 ),
				'term_taxonomy_id' => self::rng_int( $rng, -7, 2000 ),
				'object_id'        => self::rng_int( $rng, -7, 2000 ),
				'taxonomy'         => $taxonomy,
			);
		}

		$object_value       = (object) array(
			'label'  => $strings[ self::rng_int( $rng, 0, count( $strings ) - 1 ) ],
			'nested' => array( 'x' => 1 ),
		);
		$metadata_values    = array(
			null,
			true,
			false,
			0,
			-42,
			123456,
			1.25,
			'',
			$strings[0],
			$strings[ self::rng_int( $rng, 0, count( $strings ) - 1 ) ],
			'a:0:{}',
			array(),
			array( 'title' => $strings[1], 'count' => 2, 'flags' => array( true, false, null ) ),
			array( 'nested' => array( 'percent' => 'fran%c3%a7ois', 'bytes' => $strings[ self::rng_int( $rng, 0, count( $strings ) - 1 ) ] ) ),
			$object_value,
			array( 'object' => $object_value ),
		);
		$class_strings      = array(
			'alpha beta gamma',
			" leading\tmultiple\nspaces ",
			'has%20percent bad<script> quote" amp&',
			$strings[ self::rng_int( $rng, 0, count( $strings ) - 1 ) ] . ' class_two --dash',
		);
		$query_vars         = array(
			array(
				'post_type'    => 'post',
				'name'         => $strings[ self::rng_int( $rng, 0, count( $strings ) - 1 ) ],
				'p'            => '123abc',
				'bad key!'     => array( 'nested key' => $strings[2], 'ids' => array( '1', '-2', 'bad' ) ),
				'object_value' => (object) array( 'public' => 'value', 'bad key' => $strings[3] ),
			),
			array(
				's'              => $strings[ self::rng_int( $rng, 0, count( $strings ) - 1 ) ],
				'category_name'  => 'news,Updates bad%20slug',
				'tag__in'        => array( '1', 2, 'x', -4 ),
				"invalid\0key"   => "value\0with-null",
				'nested_filters' => array( array( 'year' => '2024', 'monthnum' => '02' ) ),
			),
		);
		$valid_columns      = array( 'post_date', 'post_date_gmt', 'post_modified', 'post_modified_gmt', 'comment_date', 'comment_date_gmt', 'user_registered' );
		$column             = $valid_columns[ self::rng_int( $rng, 0, count( $valid_columns ) - 1 ) ];
		$secondary_column   = $valid_columns[ self::rng_int( $rng, 0, count( $valid_columns ) - 1 ) ];
		$date_queries       = array(
			array(
				'label'                      => 'range-valid-column',
				'defaultColumn'              => $column,
				'expectBalancedParentheses'  => true,
				'query'                      => array(
					array(
						'column'    => $column,
						'after'     => '2020-01-02',
						'before'    => '2025-12-31 23:59',
						'inclusive' => (bool) self::rng_int( $rng, 0, 1 ),
					),
				),
			),
			array(
				'label'                      => 'nested-valid-column',
				'defaultColumn'              => $column,
				'expectBalancedParentheses'  => true,
				'query'                      => array(
					'relation' => 'OR',
					array(
						'column' => $column,
						'year'   => array( 2020, 2024 ),
						'month'  => array( 1, 12 ),
						'compare' => 'BETWEEN',
					),
					array(
						'column'    => $secondary_column,
						'dayofweek' => array( 1, 7 ),
						'hour'      => array( 0, 23 ),
						'compare'   => 'IN',
					),
				),
			),
			array(
				'label'                      => 'malformed-dates-valid-column',
				'defaultColumn'              => $column,
				'expectBalancedParentheses'  => true,
				'query'                      => array(
					array(
						'column'    => $column,
						'after'     => 'not-a-date',
						'before'    => '2024-99-99',
						'inclusive' => true,
					),
				),
			),
		);

		return array(
			'strings'                  => $strings,
			'posts'                    => $posts,
			'terms'                    => $terms,
			'metadataValues'           => $metadata_values,
			'serializedObjectPayloads' => array(
				'O:8:"stdClass":0:{}',
				'O:20:"DefinitelyNotLoaded":0:{}',
				'a:1:{s:1:"x";O:8:"stdClass":0:{}}',
				'E:11:"Suit:Hearts";',
			),
			'classStrings'             => $class_strings,
			'queryVars'                => $query_vars,
			'queryArgStrings'          => array(
				'post_type=post&name=Hello%20World&bad%20key=value',
				'a[b]=1&a[c][]=two&a[c][]=bad%00byte&s=' . rawurlencode( $strings[ self::rng_int( $rng, 0, count( $strings ) - 1 ) ] ),
			),
			'idList'                   => array( '1', '2', '-3', 'bad', 4, 4, '0x10' ),
			'slugList'                 => array( 'Hello World', 'fran%c3%a7%ois', $strings[ self::rng_int( $rng, 0, count( $strings ) - 1 ) ], 'two,three four' ),
			'dateQueries'              => $date_queries,
		);
	}

	private static function string_payloads( array &$rng ): array {
		$payloads = array(
			'Hello World',
			"Quote ' \" slash \\ end",
			'<p>Title &amp; "quoted"</p>',
			'fran%c3%a7%ois %zz %% already-escaped',
			"Cafe \xc3\xa9 deja \xc3\xa0",
			"Combining a\xcc\x81 a\xcc\x80 marks",
			"Emoji \xf0\x9f\x9a\x80 and CJK \xe6\xbc\xa2\xe5\xad\x97",
			"Invalid bytes " . chr( 0xff ) . chr( 0xfe ) . ' tail',
			"Overlong " . chr( 0xc0 ) . chr( 0xaf ) . ' slash',
			"Line\nTab\tNull" . chr( 0 ),
			"Entity&nbsp;dash&ndash;copy&copy;",
			'CAPS_and-dashes.under_score',
			'',
		);

		$words = array( 'alpha', 'Two Words', '100%', 'caf%C3%A9', '<tag>', "slash\\quote", "bad" . chr( 0xf5 ) . 'byte' );
		$count = self::rng_int( $rng, 2, 5 );
		$parts = array();
		for ( $i = 0; $i < $count; ++$i ) {
			$parts[] = self::rng_choice( $rng, $words );
		}
		$payloads[] = implode( self::rng_choice( $rng, array( ' ', '-', '/', '%20' ) ), $parts );

		return $payloads;
	}

	private static function generated_summary( array $inputs ): array {
		return array(
			'counts'   => array(
				'strings'          => count( $inputs['strings'] ),
				'posts'            => count( $inputs['posts'] ),
				'terms'            => count( $inputs['terms'] ),
				'metadataValues'   => count( $inputs['metadataValues'] ),
				'classStrings'     => count( $inputs['classStrings'] ),
				'queryVars'        => count( $inputs['queryVars'] ),
				'dateQueries'      => count( $inputs['dateQueries'] ),
			),
			'features' => array(
				'post-like-arrays',
				'term-like-arrays',
				'nested-metadata-arrays',
				'metadata-objects-serialize-only',
				'iso-dates',
				'malformed-dates',
				'percent-escapes',
				'unicode-utf8',
				'invalid-byte-strings',
				'class-token-strings',
				'query-var-arrays',
			),
			'strings'  => array_map( array( __CLASS__, 'describe_string' ), $inputs['strings'] ),
			'posts'    => array_map( array( __CLASS__, 'describe_post' ), $inputs['posts'] ),
			'terms'    => array_map( array( __CLASS__, 'describe_term' ), $inputs['terms'] ),
		);
	}

	private static function context_seed( \ComponentFuzz\FuzzContext $ctx ): int {
		foreach ( array( 'seed', 'getSeed', 'caseSeed', 'iterationSeed' ) as $method ) {
			if ( is_callable( array( $ctx, $method ) ) ) {
				try {
					$value = $ctx->$method();
				} catch ( \Throwable $e ) {
					continue;
				}
				if ( is_scalar( $value ) ) {
					return (int) $value;
				}
			}
		}

		foreach ( array( 'seed', 'caseSeed', 'iteration' ) as $property ) {
			try {
				if ( ( isset( $ctx->$property ) || property_exists( $ctx, $property ) ) && is_scalar( $ctx->$property ) ) {
					return (int) $ctx->$property;
				}
			} catch ( \Throwable $e ) {
				continue;
			}
		}

		return 1;
	}

	private static function rng( int $seed ): array {
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

		$out           = substr( $rng['buffer'], 0, $length );
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

	private static function rng_choice( array &$rng, array $values ) {
		return $values[ self::rng_int( $rng, 0, count( $values ) - 1 ) ];
	}

	private static function call_guarded( callable $callback ): array {
		$errors = array();
		set_error_handler(
			static function ( int $errno, string $errstr, string $errfile = '', int $errline = 0 ) use ( &$errors ): bool {
				$errors[] = array(
					'errno'   => $errno,
					'message' => $errstr,
					'file'    => basename( $errfile ),
					'line'    => $errline,
				);
				return true;
			}
		);

		try {
			$value = $callback();
			return array(
				'ok'     => true,
				'value'  => $value,
				'errors' => $errors,
			);
		} catch ( \Throwable $e ) {
			return array(
				'ok'        => false,
				'throwable' => get_class( $e ),
				'message'   => $e->getMessage(),
				'errors'    => $errors,
			);
		} finally {
			restore_error_handler();
		}
	}

	private static function call_failure( string $name, string $message, array $call, array $extra = array() ): array {
		return array_merge(
			array(
				'name'      => $name,
				'message'   => $message,
				'throwable' => $call['throwable'] ?? null,
				'error'     => $call['message'] ?? null,
				'errors'    => $call['errors'] ?? array(),
			),
			$extra
		);
	}

	private static function record( array &$result, string $name, bool $ok, array $details = array(), array $failures = array() ): void {
		$result['checks'][] = array_merge(
			array(
				'name' => $name,
				'ok'   => $ok,
			),
			$details
		);

		foreach ( $failures as $failure ) {
			$failure['check'] = $name;
			$result['failures'][] = $failure;
		}
	}

	private static function skip( array &$result, string $name, string $reason ): void {
		$result['skipped'][] = array(
			'name'   => $name,
			'reason' => $reason,
		);
	}

	private static function preview_bytes( string $bytes, int $limit = 96 ): string {
		$slice = substr( $bytes, 0, $limit );
		$json  = json_encode( $slice, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE );
		if ( false === $json ) {
			$json = base64_encode( $slice );
		}

		return strlen( $bytes ) > $limit ? $json . '...' : $json;
	}

	private static function describe_string( string $value ): array {
		return array(
			'type'      => 'string',
			'length'    => strlen( $value ),
			'sha1'      => sha1( $value ),
			'validUtf8' => 1 === preg_match( '//u', $value ),
			'preview'   => self::preview_bytes( $value ),
		);
	}

	private static function describe_scalar( $value ): array {
		if ( is_string( $value ) ) {
			return self::describe_string( $value );
		}

		return array(
			'type'  => gettype( $value ),
			'value' => is_scalar( $value ) || null === $value ? $value : null,
		);
	}

	private static function describe_value( $value, int $depth = 0 ): array {
		if ( is_string( $value ) ) {
			return self::describe_string( $value );
		}
		if ( is_scalar( $value ) || null === $value ) {
			return self::describe_scalar( $value );
		}
		if ( is_array( $value ) ) {
			$items = array();
			if ( $depth < 2 ) {
				$count = 0;
				foreach ( $value as $key => $item ) {
					$items[] = array(
						'key'   => self::describe_scalar( is_int( $key ) ? $key : (string) $key ),
						'value' => self::describe_value( $item, $depth + 1 ),
					);
					++$count;
					if ( $count >= 6 ) {
						break;
					}
				}
			}
			return array(
				'type'  => 'array',
				'count' => count( $value ),
				'items' => $items,
			);
		}
		if ( is_object( $value ) ) {
			return array(
				'type'       => 'object',
				'class'      => get_class( $value ),
				'properties' => count( get_object_vars( $value ) ),
			);
		}

		return array(
			'type' => gettype( $value ),
		);
	}

	private static function describe_post( array $post ): array {
		return array(
			'ID'             => (int) $post['ID'],
			'postTitle'      => self::describe_string( (string) $post['post_title'] ),
			'postName'       => self::describe_string( (string) $post['post_name'] ),
			'commentStatus'  => self::describe_string( (string) $post['comment_status'] ),
			'pingStatus'     => self::describe_string( (string) $post['ping_status'] ),
			'postType'       => self::describe_string( (string) $post['post_type'] ),
			'contentLength'  => strlen( (string) $post['post_content'] ),
			'excerptLength'  => strlen( (string) $post['post_excerpt'] ),
		);
	}

	private static function describe_term( array $term ): array {
		return array(
			'termId'      => (int) $term['term_id'],
			'taxonomy'    => self::describe_string( (string) $term['taxonomy'] ),
			'name'        => self::describe_string( (string) $term['name'] ),
			'slug'        => self::describe_string( (string) $term['slug'] ),
			'description' => self::describe_string( (string) $term['description'] ),
		);
	}

	private static function first_string_difference( string $left, string $right ): array {
		$max = min( strlen( $left ), strlen( $right ) );
		for ( $i = 0; $i < $max; ++$i ) {
			if ( $left[ $i ] !== $right[ $i ] ) {
				return array(
					'offset' => $i,
					'left'   => self::preview_bytes( substr( $left, max( 0, $i - 20 ), 80 ) ),
					'right'  => self::preview_bytes( substr( $right, max( 0, $i - 20 ), 80 ) ),
				);
			}
		}

		return array(
			'offset' => $max,
			'left'   => self::preview_bytes( substr( $left, max( 0, $max - 20 ), 80 ) ),
			'right'  => self::preview_bytes( substr( $right, max( 0, $max - 20 ), 80 ) ),
		);
	}

	private static function array_values_are_non_negative_ints( array $values ): bool {
		foreach ( $values as $value ) {
			if ( ! is_int( $value ) || $value < 0 ) {
				return false;
			}
		}
		return true;
	}

	private static function supported_metadata_roundtrip_value( $value ): bool {
		if ( is_object( $value ) || is_resource( $value ) ) {
			return false;
		}
		if ( is_array( $value ) ) {
			foreach ( $value as $item ) {
				if ( ! self::supported_metadata_roundtrip_value( $item ) ) {
					return false;
				}
			}
		}
		return true;
	}

	private static function serialized_payload_can_create_object( string $payload ): bool {
		return 1 === preg_match( '/(^|[;{])(?:O|C|E):\d+:/', $payload );
	}

	private static function balanced_parentheses( string $value ): bool {
		$depth = 0;
		$length = strlen( $value );
		for ( $i = 0; $i < $length; ++$i ) {
			if ( '(' === $value[ $i ] ) {
				++$depth;
			} elseif ( ')' === $value[ $i ] ) {
				--$depth;
				if ( $depth < 0 ) {
					return false;
				}
			}
		}

		return 0 === $depth;
	}

	private static function normalize_query_vars( array $query_vars ): array {
		$normalized = array();
		foreach ( $query_vars as $key => $value ) {
			$normalized_key = is_int( $key ) ? $key : self::sanitize_key_fallback( (string) $key );
			if ( '' === $normalized_key ) {
				$normalized_key = '_';
			}
			$normalized[ $normalized_key ] = self::normalize_query_value( $value );
		}

		return $normalized;
	}

	private static function normalize_query_value( $value ) {
		if ( is_array( $value ) ) {
			return self::normalize_query_vars( $value );
		}
		if ( is_object( $value ) ) {
			return self::normalize_query_vars( get_object_vars( $value ) );
		}
		if ( is_resource( $value ) || null === $value ) {
			return '';
		}
		if ( is_scalar( $value ) ) {
			return $value;
		}

		return '';
	}

	private static function query_vars_are_scalar_array_safe( $value ): bool {
		if ( is_array( $value ) ) {
			foreach ( $value as $key => $item ) {
				if ( is_string( $key ) && 1 !== preg_match( '/^[a-z0-9_-]+$/', $key ) ) {
					return false;
				}
				if ( ! is_int( $key ) && ! is_string( $key ) ) {
					return false;
				}
				if ( ! self::query_vars_are_scalar_array_safe( $item ) ) {
					return false;
				}
			}
			return true;
		}

		return is_scalar( $value ) || null === $value;
	}

	private static function sanitize_key_fallback( string $key ): string {
		if ( \function_exists( 'sanitize_key' ) ) {
			$sanitized = \sanitize_key( $key );
			return is_string( $sanitized ) ? $sanitized : '';
		}

		return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( $key ) );
	}
}
