<?php
namespace ComponentFuzz\Surfaces;

final class FilesystemSurface {
	public const NAME = 'filesystem';

	private const GENERATED_PATH_CASES     = 36;
	private const GENERATED_FILENAME_CASES = 36;
	private const MAX_PATH_BYTES           = 240;
	private const MAX_FILENAME_BYTES       = 180;
	private const MAX_FAILURES             = 12;

	public static function run( \ComponentFuzz\FuzzContext $ctx ): array {
		$missing = self::missing_core_requirements();
		if ( array() !== $missing ) {
			return array(
				$ctx->skip(
					'filesystem.bootstrap-apis-available',
					'Required WordPress filesystem/path APIs are unavailable.',
					array( 'missing' => implode( ', ', $missing ) )
				),
			);
		}

		$snapshot = self::snapshot_globals();
		$rows     = array();
		$temp_root = null;
		$cleanup  = null;

		try {
			$path_cases     = self::path_cases( $ctx );
			$filename_cases = self::filename_cases( $ctx );

			$rows[] = self::check_wp_normalize_path( $ctx, $path_cases );
			$rows[] = self::check_path_join( $ctx, $path_cases );
			$rows[] = self::check_slash_helpers( $ctx, $path_cases );
			$rows[] = self::check_validate_file( $ctx, $path_cases );
			$rows[] = self::check_sanitize_file_name( $ctx, $filename_cases );

			$temp_root = self::make_temp_root( $ctx );
			if ( null === $temp_root ) {
				$rows[] = $ctx->skip(
					'filesystem.temp-sandbox-available',
					'Could not create an isolated writable directory under /tmp.'
				);
			} else {
				$unique_dir  = $temp_root . DIRECTORY_SEPARATOR . 'unique';
				$temp_dir    = $temp_root . DIRECTORY_SEPARATOR . 'tempnam';
				$direct_dir  = $temp_root . DIRECTORY_SEPARATOR . 'direct';
				$archive_dir = $temp_root . DIRECTORY_SEPARATOR . 'archives';
				$copy_dir    = $temp_root . DIRECTORY_SEPARATOR . 'copy-move';

				self::ensure_dir( $unique_dir );
				self::ensure_dir( $temp_dir );
				self::ensure_dir( $direct_dir );
				self::ensure_dir( $archive_dir );
				self::ensure_dir( $copy_dir );

				$rows[] = self::check_wp_unique_filename( $ctx, $filename_cases, $unique_dir );
				$rows[] = self::check_wp_unique_filename_callbacks_and_case( $ctx, $unique_dir );
				$rows[] = self::check_wp_tempnam( $ctx, $filename_cases, $temp_dir );
				$rows[] = self::check_recursive_directory_helpers( $ctx, $filename_cases, $direct_dir );
				$rows[] = self::check_wp_filesystem_direct( $ctx, $filename_cases, $direct_dir );
				$rows[] = self::check_wp_filesystem_direct_metadata( $ctx, $filename_cases, $direct_dir );
				$rows[] = self::check_zip_archive_extraction( $ctx, $archive_dir );
				$rows[] = self::check_copy_move_dir_contracts( $ctx, $filename_cases, $copy_dir );
			}
		} catch ( \Throwable $e ) {
			$rows[] = $ctx->fail(
				'filesystem.surface-no-throw',
				array(
					'throwable' => self::describe_throwable( $e ),
				)
			);
		} finally {
			if ( null !== $temp_root ) {
				$cleanup = self::remove_dir_recursive( $temp_root );
			}
			self::restore_globals( $snapshot );
		}

		if ( null !== $temp_root ) {
			$rows[] = $ctx->result(
				'filesystem.temp-sandbox-cleaned',
				true === $cleanup,
				array(
					'root'    => self::describe_string( $temp_root ),
					'cleaned' => true === $cleanup,
				)
			);
		}

		return $rows;
	}

	private static function missing_core_requirements(): array {
		$missing = array();
		foreach (
			array(
				'path_join',
				'trailingslashit',
				'untrailingslashit',
				'validate_file',
				'wp_normalize_path',
				'sanitize_file_name',
				'wp_unique_filename',
				'wp_mkdir_p',
				'wp_is_stream',
				'wp_is_writable',
				'list_files',
				'wp_zip_file_is_valid',
				'unzip_file',
				'copy_dir',
				'move_dir',
			) as $function
		) {
			if ( ! function_exists( $function ) ) {
				$missing[] = "function {$function}";
			}
		}

		return $missing;
	}

	private static function check_wp_normalize_path( \ComponentFuzz\FuzzContext $ctx, array $cases ): array {
		$failures       = array();
		$duplicate_runs = 0;
		$drive_runs     = 0;

		foreach ( $cases as $case ) {
			$path  = $case['value'];
			$first = self::call(
				static function () use ( $path ) {
					return \wp_normalize_path( $path );
				}
			);

			if ( $first['threw'] || ! is_string( $first['value'] ) ) {
				self::record_failure(
					$failures,
					'wp_normalize_path.no-throw-return-string',
					$case,
					array( 'call' => self::describe_call( $first ) )
				);
				continue;
			}

			$normalized = $first['value'];
			$second     = self::call(
				static function () use ( $normalized ) {
					return \wp_normalize_path( $normalized );
				}
			);
			if ( $second['threw'] || $second['value'] !== $normalized ) {
				self::record_failure(
					$failures,
					'wp_normalize_path.idempotent',
					$case,
					array(
						'first'  => self::describe_string( $normalized ),
						'second' => self::describe_call( $second ),
					)
				);
			}

			$expected = self::expected_normalized_path( $path );
			if ( $expected !== $normalized ) {
				self::record_failure(
					$failures,
					'wp_normalize_path.oracle',
					$case,
					array(
						'expected' => self::describe_string( $expected ),
						'actual'   => self::describe_string( $normalized ),
					)
				);
			}

			if ( false !== strpos( $normalized, '\\' ) ) {
				self::record_failure(
					$failures,
					'wp_normalize_path.no-backslashes',
					$case,
					array( 'actual' => self::describe_string( $normalized ) )
				);
			}

			if ( self::has_disallowed_duplicate_slashes( $normalized ) ) {
				self::record_failure(
					$failures,
					'wp_normalize_path.duplicate-slash-policy',
					$case,
					array( 'actual' => self::describe_string( $normalized ) )
				);
			} elseif ( in_array( 'repeated-separator', $case['features'], true ) ) {
				++$duplicate_runs;
			}

			if ( self::has_lowercase_drive_letter( $normalized ) ) {
				self::record_failure(
					$failures,
					'wp_normalize_path.uppercase-drive-letter',
					$case,
					array( 'actual' => self::describe_string( $normalized ) )
				);
			} elseif ( in_array( 'windows-drive', $case['features'], true ) ) {
				++$drive_runs;
			}
		}

		return self::row(
			$ctx,
			'filesystem.wp_normalize_path.separator-idempotence',
			$cases,
			$failures,
			array(
				'repeatedSeparatorCases' => $duplicate_runs,
				'windowsDriveCases'      => $drive_runs,
			)
		);
	}

	private static function check_path_join( \ComponentFuzz\FuzzContext $ctx, array $cases ): array {
		$failures       = array();
		$join_cases     = 0;
		$absolute_cases = 0;
		$nul_skipped    = 0;
		$case_count     = count( $cases );

		foreach ( $cases as $index => $case ) {
			$base_case = $cases[ ( $index * 7 + 3 ) % $case_count ];
			$base      = $base_case['value'];
			$path      = $case['value'];
			if ( false !== strpos( $path, chr( 0 ) ) ) {
				++$nul_skipped;
				continue;
			}

			$expected  = self::expected_path_join( $base, $path );
			$joined    = self::call(
				static function () use ( $base, $path ) {
					return \path_join( $base, $path );
				}
			);

			if ( self::path_is_absolute_like_core( $path ) ) {
				++$absolute_cases;
			} else {
				++$join_cases;
			}

			if ( $joined['threw'] || ! is_string( $joined['value'] ) ) {
				self::record_failure(
					$failures,
					'path_join.no-throw-return-string',
					$case,
					array(
						'base' => self::describe_string( $base ),
						'call' => self::describe_call( $joined ),
					)
				);
				continue;
			}

			if ( $joined['value'] !== $expected ) {
				self::record_failure(
					$failures,
					'path_join.absolute-or-join-oracle',
					$case,
					array(
						'base'     => self::describe_string( $base ),
						'expected' => self::describe_string( $expected ),
						'actual'   => self::describe_string( $joined['value'] ),
					)
				);
			}

			if ( self::path_is_absolute_like_core( $path ) && $joined['value'] !== $path ) {
				self::record_failure(
					$failures,
					'path_join.absolute-path-unchanged',
					$case,
					array(
						'base'   => self::describe_string( $base ),
						'actual' => self::describe_string( $joined['value'] ),
					)
				);
			}
		}

		return self::row(
			$ctx,
			'filesystem.path_join.absolute-or-relative-contract',
			$cases,
			$failures,
			array(
				'relativeJoinCases' => $join_cases,
				'absoluteCases'     => $absolute_cases,
				'nulSkippedCases'   => $nul_skipped,
			)
		);
	}

	private static function check_slash_helpers( \ComponentFuzz\FuzzContext $ctx, array $cases ): array {
		$failures             = array();
		$trailing_slash_cases = 0;
		$trailing_back_cases  = 0;

		foreach ( $cases as $case ) {
			$value = $case['value'];
			$trail = self::call(
				static function () use ( $value ) {
					return \trailingslashit( $value );
				}
			);
			$untrail = self::call(
				static function () use ( $value ) {
					return \untrailingslashit( $value );
				}
			);

			if ( $trail['threw'] || $untrail['threw'] || ! is_string( $trail['value'] ) || ! is_string( $untrail['value'] ) ) {
				self::record_failure(
					$failures,
					'slash-helpers.no-throw-return-string',
					$case,
					array(
						'trailingslashit'   => self::describe_call( $trail ),
						'untrailingslashit' => self::describe_call( $untrail ),
					)
				);
				continue;
			}

			if ( '' !== $value && '/' === substr( $value, -1 ) ) {
				++$trailing_slash_cases;
			}
			if ( '' !== $value && '\\' === substr( $value, -1 ) ) {
				++$trailing_back_cases;
			}

			$trail_again = self::call(
				static function () use ( $trail ) {
					return \trailingslashit( $trail['value'] );
				}
			);
			$untrail_again = self::call(
				static function () use ( $untrail ) {
					return \untrailingslashit( $untrail['value'] );
				}
			);

			if ( $trail_again['threw'] || $trail_again['value'] !== $trail['value'] ) {
				self::record_failure(
					$failures,
					'trailingslashit.idempotent',
					$case,
					array(
						'first'  => self::describe_string( $trail['value'] ),
						'second' => self::describe_call( $trail_again ),
					)
				);
			}

			if ( $untrail_again['threw'] || $untrail_again['value'] !== $untrail['value'] ) {
				self::record_failure(
					$failures,
					'untrailingslashit.idempotent',
					$case,
					array(
						'first'  => self::describe_string( $untrail['value'] ),
						'second' => self::describe_call( $untrail_again ),
					)
				);
			}

			if ( $trail['value'] !== $untrail['value'] . '/' ) {
				self::record_failure(
					$failures,
					'trailingslashit.equals-untrail-plus-forward-slash',
					$case,
					array(
						'trailingslashit'   => self::describe_string( $trail['value'] ),
						'untrailingslashit' => self::describe_string( $untrail['value'] ),
					)
				);
			}

			if ( '' !== $trail['value'] && '/' !== substr( $trail['value'], -1 ) ) {
				self::record_failure(
					$failures,
					'trailingslashit.ends-forward-slash',
					$case,
					array( 'actual' => self::describe_string( $trail['value'] ) )
				);
			}

			if ( '' !== $untrail['value'] && false !== strpbrk( substr( $untrail['value'], -1 ), '/\\' ) ) {
				self::record_failure(
					$failures,
					'untrailingslashit.removes-trailing-separators',
					$case,
					array( 'actual' => self::describe_string( $untrail['value'] ) )
				);
			}
		}

		return self::row(
			$ctx,
			'filesystem.slash-helpers.idempotence',
			$cases,
			$failures,
			array(
				'trailingSlashInputs'     => $trailing_slash_cases,
				'trailingBackslashInputs' => $trailing_back_cases,
			)
		);
	}

	private static function check_validate_file( \ComponentFuzz\FuzzContext $ctx, array $cases ): array {
		$failures      = array();
		$allowed_files = array(
			'safe/file.txt',
			'safe/nested/plugin.php',
			'a/../',
			'folder/sub/file.php',
			'windows/normalized.txt',
		);
		$class_counts  = array(
			'traversal'       => 0,
			'windows-drive'   => 0,
			'absolute-listed' => 0,
			'allowed-list'    => 0,
			'accepted'        => 0,
		);

		foreach ( $cases as $case ) {
			$path         = $case['value'];
			$default_call = self::call(
				static function () use ( $path ) {
					return \validate_file( $path );
				}
			);
			$allowed_call = self::call(
				static function () use ( $path, $allowed_files ) {
					return \validate_file( $path, $allowed_files );
				}
			);

			if ( $default_call['threw'] || ! is_int( $default_call['value'] ) ) {
				self::record_failure(
					$failures,
					'validate_file.default-no-throw-return-int',
					$case,
					array( 'call' => self::describe_call( $default_call ) )
				);
				continue;
			}

			if ( $allowed_call['threw'] || ! is_int( $allowed_call['value'] ) ) {
				self::record_failure(
					$failures,
					'validate_file.allowed-no-throw-return-int',
					$case,
					array( 'call' => self::describe_call( $allowed_call ) )
				);
				continue;
			}

			$expected_default = self::expected_validate_file_code( $path, array() );
			$expected_allowed = self::expected_validate_file_code( $path, $allowed_files );

			if ( $default_call['value'] !== $expected_default ) {
				self::record_failure(
					$failures,
					'validate_file.default-code-oracle',
					$case,
					array(
						'expected' => $expected_default,
						'actual'   => $default_call['value'],
					)
				);
			}

			if ( $allowed_call['value'] !== $expected_allowed ) {
				self::record_failure(
					$failures,
					'validate_file.allowed-list-code-oracle',
					$case,
					array(
						'allowedFiles' => $allowed_files,
						'expected'     => $expected_allowed,
						'actual'       => $allowed_call['value'],
					)
				);
			}

			if ( 1 === $default_call['value'] ) {
				++$class_counts['traversal'];
			} elseif ( 2 === $default_call['value'] ) {
				++$class_counts['windows-drive'];
			} elseif ( 0 === $default_call['value'] ) {
				++$class_counts['accepted'];
			}

			if ( self::path_is_absolute_like_core( $path ) && 0 !== $allowed_call['value'] ) {
				++$class_counts['absolute-listed'];
			}
			if ( 3 === $allowed_call['value'] ) {
				++$class_counts['allowed-list'];
			}
		}

		return self::row(
			$ctx,
			'filesystem.validate_file.rejection-classes',
			$cases,
			$failures,
			array(
				'allowedFiles' => $allowed_files,
				'classCounts'  => $class_counts,
			)
		);
	}

	private static function check_sanitize_file_name( \ComponentFuzz\FuzzContext $ctx, array $cases ): array {
		$failures             = array();
		$empty_outputs        = 0;
		$multi_extension_runs = 0;
		$control_runs         = 0;

		foreach ( $cases as $case ) {
			$filename  = $case['value'];
			$sanitized = self::call(
				static function () use ( $filename ) {
					return \sanitize_file_name( $filename );
				}
			);

			if ( $sanitized['threw'] || ! is_string( $sanitized['value'] ) ) {
				self::record_failure(
					$failures,
					'sanitize_file_name.no-throw-return-string',
					$case,
					array( 'call' => self::describe_call( $sanitized ) )
				);
				continue;
			}

			$value = $sanitized['value'];
			if ( '' === $value ) {
				++$empty_outputs;
			}
			if ( in_array( 'control-byte', $case['features'], true ) || in_array( 'nul-byte', $case['features'], true ) ) {
				++$control_runs;
			}
			if ( in_array( 'multi-extension', $case['features'], true ) ) {
				++$multi_extension_runs;
			}

			$again = self::call(
				static function () use ( $value ) {
					return \sanitize_file_name( $value );
				}
			);
			if ( $again['threw'] || $again['value'] !== $value ) {
				self::record_failure(
					$failures,
					'sanitize_file_name.idempotent',
					$case,
					array(
						'first'  => self::describe_string( $value ),
						'second' => self::describe_call( $again ),
					)
				);
			}

			if ( self::filename_has_forbidden_output_chars( $value ) ) {
				self::record_failure(
					$failures,
					'sanitize_file_name.no-path-separator-nul-or-line-control',
					$case,
					array( 'actual' => self::describe_string( $value ) )
				);
			}

			if ( false !== strpos( $value, '..' ) ) {
				self::record_failure(
					$failures,
					'sanitize_file_name.no-repeated-dots',
					$case,
					array( 'actual' => self::describe_string( $value ) )
				);
			}

			if ( $value !== trim( $value, '.-_' ) ) {
				self::record_failure(
					$failures,
					'sanitize_file_name.trims-edge-dot-dash-underscore',
					$case,
					array( 'actual' => self::describe_string( $value ) )
				);
			}

			$dangerous = self::dangerous_intermediate_extensions( $value );
			if ( array() !== $dangerous ) {
				self::record_failure(
					$failures,
					'sanitize_file_name.dangerous-intermediate-extensions-postfixed',
					$case,
					array(
						'actual'     => self::describe_string( $value ),
						'extensions' => $dangerous,
					)
				);
			}
		}

		return self::row(
			$ctx,
			'filesystem.sanitize_file_name.output-safety-idempotence',
			$cases,
			$failures,
			array(
				'emptyOutputs'        => $empty_outputs,
				'controlInputCases'   => $control_runs,
				'multiExtensionCases' => $multi_extension_runs,
			)
		);
	}

	private static function check_wp_unique_filename( \ComponentFuzz\FuzzContext $ctx, array $cases, string $dir ): array {
		if ( ! function_exists( 'wp_unique_filename' ) ) {
			return $ctx->skip( 'filesystem.wp_unique_filename.available', 'wp_unique_filename() is unavailable.' );
		}

		$failures = array();
		$existing = array(
			'photo.jpg',
			'photo-1.jpg',
			'image.JPG',
			'report.pdf',
			'archive.tar.gz',
			'name-150x150.png',
			'name-150x150-1.png',
			'thumb-rotated.webp',
			'thumb-rotated-1.webp',
			'.hidden',
			'multi.php_.jpg',
			'-1',
		);

		foreach ( $existing as $name ) {
			file_put_contents( $dir . DIRECTORY_SEPARATOR . $name, 'existing' );
		}

		$selected = self::mixed_case_sample( $cases, 18, 24 );
		foreach ( $selected as $case ) {
			$filename = $case['value'];
			$unique   = self::call(
				static function () use ( $dir, $filename ) {
					return \wp_unique_filename( $dir, $filename );
				}
			);

			if ( $unique['threw'] || ! is_string( $unique['value'] ) ) {
				self::record_failure(
					$failures,
					'wp_unique_filename.no-throw-return-string',
					$case,
					array( 'call' => self::describe_call( $unique ) )
				);
				continue;
			}

			$value = $unique['value'];
			if ( '' === $value || self::filename_has_forbidden_output_chars( $value ) ) {
				self::record_failure(
					$failures,
					'wp_unique_filename.safe-basename',
					$case,
					array( 'actual' => self::describe_string( $value ) )
				);
			}

			if ( file_exists( $dir . DIRECTORY_SEPARATOR . $value ) ) {
				self::record_failure(
					$failures,
					'wp_unique_filename.non-conflicting',
					$case,
					array(
						'actual' => self::describe_string( $value ),
						'dir'    => self::describe_string( $dir ),
					)
				);
			}

			$sanitized = self::call(
				static function () use ( $value ) {
					return \sanitize_file_name( $value );
				}
			);
			$input_sanitized = \sanitize_file_name( $filename );
			$empty_legacy    = '' === $input_sanitized && 1 === preg_match( '/^-\d+$/', $value );
			if ( ! $empty_legacy && ( $sanitized['threw'] || $sanitized['value'] !== $value ) ) {
				self::record_failure(
					$failures,
					'wp_unique_filename.sanitized-output',
					$case,
					array(
						'unique'    => self::describe_string( $value ),
						'sanitized' => self::describe_call( $sanitized ),
					)
				);
			}

			$again = self::call(
				static function () use ( $dir, $filename ) {
					return \wp_unique_filename( $dir, $filename );
				}
			);
			if ( $again['threw'] || $again['value'] !== $value ) {
				self::record_failure(
					$failures,
					'wp_unique_filename.repeat-stable-without-creation',
					$case,
					array(
						'first'  => self::describe_string( $value ),
						'second' => self::describe_call( $again ),
					)
				);
			}
		}

		return self::row(
			$ctx,
			'filesystem.wp_unique_filename.sandbox-uniqueness',
			$selected,
			$failures,
			array(
				'existingFixtures' => $existing,
				'sandbox'          => self::describe_string( $dir ),
			)
		);
	}

	private static function check_wp_unique_filename_callbacks_and_case( \ComponentFuzz\FuzzContext $ctx, string $dir ): array {
		if ( ! function_exists( 'wp_unique_filename' ) ) {
			return $ctx->skip( 'filesystem.wp_unique_filename.callback-available', 'wp_unique_filename() is unavailable.' );
		}

		$failures       = array();
		$case_basename  = 'Case-' . $ctx->int( 10, 99 );
		$callback_token = 'cb-' . strtolower( $ctx->identifier( 4, 8 ) );
		$callback_calls = array();
		$filter_events  = array();
		$filter         = static function ( string $filename, string $ext, string $seen_dir, $callback, array $alt_filenames, $number ) use ( &$filter_events ): string {
			$filter_events[] = array(
				'filename'    => $filename,
				'ext'         => $ext,
				'dir'         => $seen_dir,
				'hasCallback' => is_callable( $callback ),
				'alt'         => $alt_filenames,
				'number'      => $number,
			);

			return $filename;
		};
		$callback       = static function ( string $seen_dir, string $name, string $ext ) use ( &$callback_calls, $callback_token ): string {
			$callback_calls[] = array(
				'dir'  => $seen_dir,
				'name' => $name,
				'ext'  => $ext,
			);

			return $callback_token . '-' . strtolower( preg_replace( '/[^a-z0-9]+/i', '-', $name ) ) . $ext;
		};

		file_put_contents( $dir . DIRECTORY_SEPARATOR . $case_basename . '.jpg', 'lowercase collision' );
		file_put_contents( $dir . DIRECTORY_SEPARATOR . $case_basename . '-1.jpg', 'numbered lowercase collision' );

		\add_filter( 'wp_unique_filename', $filter, 10, 6 );
		try {
			$uppercase = \wp_unique_filename( $dir, $case_basename . '.JPG' );
			$callback_result = \wp_unique_filename( $dir, ' Callback Name ' . $ctx->int( 100, 999 ) . '.TXT ', $callback );
		} finally {
			$removed = \remove_filter( 'wp_unique_filename', $filter, 10 );
		}

		$case_ok = $case_basename . '-2.jpg' === $uppercase
			&& 1 === count( $callback_calls )
			&& $dir === ( $callback_calls[0]['dir'] ?? null )
			&& '.TXT' === ( $callback_calls[0]['ext'] ?? null )
			&& str_starts_with( $callback_result, $callback_token . '-' )
			&& str_ends_with( $callback_result, '.TXT' );
		if ( ! $case_ok ) {
			self::record_failure(
				$failures,
				'wp_unique_filename lowercases uppercase image extensions after checking lowercase collisions and calls custom callbacks once',
				array(
					'value'    => $case_basename . '.JPG',
					'source'   => 'generated',
					'features' => array( 'uppercase-extension', 'collision' ),
				),
				array(
					'caseBase'      => $case_basename,
					'uppercase'     => $uppercase,
					'callback'      => $callback_result,
					'callbackCalls' => $callback_calls,
				)
			);
		}

		$filter_ok = 2 === count( $filter_events )
			&& $case_basename . '-2.jpg' === ( $filter_events[0]['filename'] ?? null )
			&& '.JPG' === ( $filter_events[0]['ext'] ?? null )
			&& isset( $filter_events[0]['alt']['.jpg'] )
			&& $case_basename . '-2.jpg' === $filter_events[0]['alt']['.jpg']
			&& 2 === ( $filter_events[0]['number'] ?? null )
			&& $callback_result === ( $filter_events[1]['filename'] ?? null )
			&& true === ( $filter_events[1]['hasCallback'] ?? null )
			&& array() === ( $filter_events[1]['alt'] ?? null )
			&& '' === (string) ( $filter_events[1]['number'] ?? '' )
			&& $removed
			&& false === \has_filter( 'wp_unique_filename', $filter );
		if ( ! $filter_ok ) {
			self::record_failure(
				$failures,
				'wp_unique_filename filter exposes collision number and callback metadata, then is removed',
				array(
					'value'    => $case_basename . '.JPG',
					'source'   => 'generated',
					'features' => array( 'filter-metadata' ),
				),
				array(
					'events'  => $filter_events,
					'removed' => $removed ?? null,
					'has'     => \has_filter( 'wp_unique_filename', $filter ),
				)
			);
		}

		return self::row(
			$ctx,
			'filesystem.wp_unique_filename.callback-and-case-collisions',
			array(
				array(
					'value'    => $case_basename . '.JPG',
					'source'   => 'generated',
					'features' => array( 'uppercase-extension', 'collision' ),
				),
			),
			$failures,
			array(
				'sandbox' => self::describe_string( $dir ),
			)
		);
	}

	private static function check_wp_tempnam( \ComponentFuzz\FuzzContext $ctx, array $cases, string $dir ): array {
		if ( ! function_exists( 'wp_tempnam' ) ) {
			return $ctx->skip( 'filesystem.wp_tempnam.available', 'wp_tempnam() is unavailable.' );
		}

		$failures      = array();
		$created_count = 0;
		$cleaned_count = 0;
		$selected      = self::mixed_case_sample( $cases, 6, 8 );
		$allowed_dir   = \trailingslashit( $dir );

		foreach ( $selected as $case ) {
			$filename = $case['value'];
			$temp     = self::call(
				static function () use ( $filename, $allowed_dir ) {
					return \wp_tempnam( $filename, $allowed_dir );
				}
			);

			if ( $temp['threw'] || ! is_string( $temp['value'] ) ) {
				self::record_failure(
					$failures,
					'wp_tempnam.no-throw-return-string',
					$case,
					array( 'call' => self::describe_call( $temp ) )
				);
				continue;
			}

			$path = $temp['value'];
			if ( ! self::path_is_inside_directory( $path, $allowed_dir ) ) {
				self::record_failure(
					$failures,
					'wp_tempnam.path-under-allowed-temp-dir',
					$case,
					array(
						'actual'     => self::describe_string( $path ),
						'allowedDir' => self::describe_string( $allowed_dir ),
					)
				);
				continue;
			}

			if ( ! is_file( $path ) || ! is_writable( $path ) ) {
				self::record_failure(
					$failures,
					'wp_tempnam.creates-writable-file',
					$case,
					array(
						'actual'     => self::describe_string( $path ),
						'isFile'     => is_file( $path ),
						'isWritable' => is_writable( $path ),
					)
				);
			} else {
				++$created_count;
			}

			$basename = basename( $path );
			if ( ! str_ends_with( $basename, '.tmp' ) || strlen( $basename ) > 252 || self::filename_has_forbidden_output_chars( $basename ) ) {
				self::record_failure(
					$failures,
					'wp_tempnam.safe-bounded-tmp-basename',
					$case,
					array( 'basename' => self::describe_string( $basename ) )
				);
			}

			if ( self::path_is_inside_directory( $path, '/tmp' ) && @unlink( $path ) && ! file_exists( $path ) ) {
				++$cleaned_count;
			} else {
				self::record_failure(
					$failures,
					'wp_tempnam.created-file-cleanable',
					$case,
					array( 'actual' => self::describe_string( $path ) )
				);
			}
		}

		return self::row(
			$ctx,
			'filesystem.wp_tempnam.allowed-temp-dir-lifecycle',
			$selected,
			$failures,
			array(
				'allowedDir' => self::describe_string( $allowed_dir ),
				'created'    => $created_count,
				'cleaned'    => $cleaned_count,
			)
		);
	}

	private static function check_recursive_directory_helpers( \ComponentFuzz\FuzzContext $ctx, array $cases, string $dir ): array {
		$failures = array();
		$root     = $dir . DIRECTORY_SEPARATOR . 'recursive-' . $ctx->iteration();
		$branch   = self::safe_leaf_name( $cases[ $ctx->int( 0, count( $cases ) - 1 ) ]['value'], 'branch' );
		$leaf     = self::safe_leaf_name( $cases[ $ctx->int( 0, count( $cases ) - 1 ) ]['value'], 'leaf.txt' );
		$nested   = $root . DIRECTORY_SEPARATOR . $branch . DIRECTORY_SEPARATOR . 'deep';
		$file     = $nested . DIRECTORY_SEPARATOR . $leaf;
		$hidden   = $nested . DIRECTORY_SEPARATOR . '.hidden-' . $ctx->iteration() . '.txt';
		$excluded = $root . DIRECTORY_SEPARATOR . 'excluded-' . $ctx->iteration() . '.txt';
		$contents = 'filesystem-recursive-' . $ctx->seed() . '-' . $ctx->iteration();

		$mkdir_nested = self::call(
			static function () use ( $nested ) {
				return \wp_mkdir_p( $nested );
			}
		);
		$mkdir_again = self::call(
			static function () use ( $nested ) {
				return \wp_mkdir_p( \trailingslashit( $nested ) );
			}
		);

		if (
			$mkdir_nested['threw']
			|| true !== $mkdir_nested['value']
			|| $mkdir_again['threw']
			|| true !== $mkdir_again['value']
			|| ! is_dir( $nested )
			|| ! self::path_is_inside_directory( $nested, $dir )
			|| ! \wp_is_writable( $nested )
		) {
			self::record_failure(
				$failures,
				'wp_mkdir_p.recursive-idempotent-sandbox-directory',
				array(
					'value'    => $nested,
					'source'   => 'generated',
					'features' => array( 'recursive-directory' ),
				),
				array(
					'mkdir'      => self::describe_call( $mkdir_nested ),
					'mkdirAgain' => self::describe_call( $mkdir_again ),
					'isDir'      => is_dir( $nested ),
					'writable'   => \wp_is_writable( $nested ),
				)
			);
		}

		file_put_contents( $file, $contents );
		file_put_contents( $hidden, 'hidden' );
		file_put_contents( $excluded, 'excluded' );

		$list_visible = self::call(
			static function () use ( $root, $excluded ) {
				return \list_files( $root, 100, array( basename( $excluded ) ), false );
			}
		);
		$list_hidden = self::call(
			static function () use ( $root ) {
				return \list_files( $root, 100, array(), true );
			}
		);
		$list_depth_one = self::call(
			static function () use ( $root, $excluded ) {
				return \list_files( $root, 1, array( basename( $excluded ) ), true );
			}
		);
		$list_zero = self::call(
			static function () use ( $root ) {
				return \list_files( $root, 0, array(), true );
			}
		);

		$visible_files   = self::normalized_list_files_result( $list_visible['value'] ?? false );
		$hidden_files    = self::normalized_list_files_result( $list_hidden['value'] ?? false );
		$depth_one_files = self::normalized_list_files_result( $list_depth_one['value'] ?? false );
		$expected_file   = \wp_normalize_path( $file );
		$expected_hidden = \wp_normalize_path( $hidden );
		$expected_excl   = \wp_normalize_path( $excluded );
		$expected_branch = \trailingslashit( \wp_normalize_path( $root . DIRECTORY_SEPARATOR . $branch ) );

		if (
			$list_visible['threw']
			|| $list_hidden['threw']
			|| $list_depth_one['threw']
			|| $list_zero['threw']
			|| ! is_array( $list_visible['value'] )
			|| ! is_array( $list_hidden['value'] )
			|| ! is_array( $list_depth_one['value'] )
			|| false !== $list_zero['value']
			|| ! in_array( $expected_file, $visible_files, true )
			|| in_array( $expected_hidden, $visible_files, true )
			|| in_array( $expected_excl, $visible_files, true )
			|| ! in_array( $expected_hidden, $hidden_files, true )
			|| ! in_array( $expected_excl, $hidden_files, true )
			|| array( $expected_branch ) !== $depth_one_files
		) {
			self::record_failure(
				$failures,
				'list_files.recursive-hidden-exclusion-depth-contract',
				array(
					'value'    => $root,
					'source'   => 'generated',
					'features' => array( 'recursive-directory', 'hidden-file', 'excluded-file' ),
				),
				array(
					'visible'       => self::describe_call( $list_visible ),
					'hidden'        => self::describe_call( $list_hidden ),
					'depthOne'      => self::describe_call( $list_depth_one ),
					'zero'          => self::describe_call( $list_zero ),
					'expectedFile'  => $expected_file,
					'expectedHidden' => $expected_hidden,
					'expectedExcl'  => $expected_excl,
					'expectedBranch' => $expected_branch,
				)
			);
		}

		$stream_cases = array(
			'file://' . $file  => \in_array( 'file', stream_get_wrappers(), true ),
			'php://memory'    => \in_array( 'php', stream_get_wrappers(), true ),
			'not-a-stream://' . $leaf => \in_array( 'not-a-stream', stream_get_wrappers(), true ),
			$file             => false,
		);
		foreach ( $stream_cases as $path => $expected ) {
			$actual = \wp_is_stream( $path );
			if ( $actual !== $expected ) {
				self::record_failure(
					$failures,
					'wp_is_stream.registered-wrapper-contract',
					array(
						'value'    => $path,
						'source'   => 'generated',
						'features' => array( 'stream-detection' ),
					),
					array(
						'expected' => $expected,
						'actual'   => $actual,
						'wrappers' => stream_get_wrappers(),
					)
				);
			}
		}

		return self::row(
			$ctx,
			'filesystem.recursive-directory-listing-and-stream-contracts',
			array(
				array(
					'value'    => $root,
					'source'   => 'generated',
					'features' => array( 'recursive-directory', 'hidden-file', 'excluded-file', 'stream-detection' ),
				),
			),
			$failures,
			array(
				'sandbox' => self::describe_string( $dir ),
				'root'    => self::describe_string( $root ),
				'branch'  => self::describe_string( $branch ),
				'leaf'    => self::describe_string( $leaf ),
			)
		);
	}

	private static function check_wp_filesystem_direct( \ComponentFuzz\FuzzContext $ctx, array $cases, string $dir ): array {
		if ( ! class_exists( 'WP_Filesystem_Direct' ) ) {
			return $ctx->skip( 'filesystem.WP_Filesystem_Direct.available', 'WP_Filesystem_Direct is unavailable.' );
		}

		$failures = array();
		$fs       = new \WP_Filesystem_Direct( null );
		$leaf     = self::safe_leaf_name( $cases[ $ctx->int( 0, count( $cases ) - 1 ) ]['value'], 'case-file.txt' );
		$subdir   = $dir . DIRECTORY_SEPARATOR . 'subdir-' . $ctx->iteration();
		$file     = $subdir . DIRECTORY_SEPARATOR . $leaf;
		$hidden   = $subdir . DIRECTORY_SEPARATOR . '.hidden-' . $ctx->iteration();
		$copy     = $subdir . DIRECTORY_SEPARATOR . 'copy-' . $leaf;
		$moved    = $subdir . DIRECTORY_SEPARATOR . 'moved-' . $leaf;
		$contents = "seed=" . $ctx->seed() . "\niteration=" . $ctx->iteration() . "\n";

		if ( ! self::path_is_inside_directory( $subdir, $dir ) ) {
			return $ctx->fail(
				'filesystem.WP_Filesystem_Direct.sandbox-paths',
				array(
					'sandbox' => self::describe_string( $dir ),
					'file'    => self::describe_string( $file ),
				)
			);
		}

		$mkdir = self::call(
			static function () use ( $fs, $subdir ) {
				return $fs->mkdir( $subdir, 0755 );
			}
		);
		if ( $mkdir['threw'] || true !== $mkdir['value'] || ! $fs->is_dir( $subdir ) ) {
			self::record_failure(
				$failures,
				'WP_Filesystem_Direct.mkdir-is_dir',
				array( 'value' => $subdir, 'features' => array( 'direct' ), 'source' => 'sandbox' ),
				array(
					'mkdir' => self::describe_call( $mkdir ),
					'isDir' => $fs->is_dir( $subdir ),
				)
			);
		}

		$put = self::call(
			static function () use ( $fs, $file, $contents ) {
				return $fs->put_contents( $file, $contents, 0644 );
			}
		);
		file_put_contents( $hidden, "hidden\n" );

		if ( $put['threw'] || true !== $put['value'] || ! $fs->exists( $file ) || ! $fs->is_file( $file ) ) {
			self::record_failure(
				$failures,
				'WP_Filesystem_Direct.put-exists-is_file',
				array( 'value' => $file, 'features' => array( 'direct' ), 'source' => 'sandbox' ),
				array(
					'put'    => self::describe_call( $put ),
					'exists' => $fs->exists( $file ),
					'isFile' => $fs->is_file( $file ),
				)
			);
		}

		$read = self::call(
			static function () use ( $fs, $file ) {
				return $fs->get_contents( $file );
			}
		);
		if ( $read['threw'] || $read['value'] !== $contents || $fs->size( $file ) !== strlen( $contents ) ) {
			self::record_failure(
				$failures,
				'WP_Filesystem_Direct.read-size-roundtrip',
				array( 'value' => $file, 'features' => array( 'direct' ), 'source' => 'sandbox' ),
				array(
					'read' => self::describe_call( $read ),
					'size' => $fs->size( $file ),
				)
			);
		}

		$copy_call = self::call(
			static function () use ( $fs, $file, $copy ) {
				return $fs->copy( $file, $copy, false, 0644 );
			}
		);
		$copy_again = self::call(
			static function () use ( $fs, $file, $copy ) {
				return $fs->copy( $file, $copy, false, 0644 );
			}
		);
		if ( $copy_call['threw'] || true !== $copy_call['value'] || $copy_again['threw'] || false !== $copy_again['value'] || $fs->get_contents( $copy ) !== $contents ) {
			self::record_failure(
				$failures,
				'WP_Filesystem_Direct.copy-overwrite-policy',
				array( 'value' => $copy, 'features' => array( 'direct' ), 'source' => 'sandbox' ),
				array(
					'copy'       => self::describe_call( $copy_call ),
					'copyAgain'  => self::describe_call( $copy_again ),
					'copyExists' => $fs->exists( $copy ),
				)
			);
		}

		$move = self::call(
			static function () use ( $fs, $copy, $moved ) {
				return $fs->move( $copy, $moved, false );
			}
		);
		if ( $move['threw'] || true !== $move['value'] || $fs->exists( $copy ) || ! $fs->exists( $moved ) || $fs->get_contents( $moved ) !== $contents ) {
			self::record_failure(
				$failures,
				'WP_Filesystem_Direct.move-source-gone-dest-readable',
				array( 'value' => $moved, 'features' => array( 'direct' ), 'source' => 'sandbox' ),
				array(
					'move'        => self::describe_call( $move ),
					'sourceExist' => $fs->exists( $copy ),
					'destExist'   => $fs->exists( $moved ),
				)
			);
		}

		$list_visible = self::call(
			static function () use ( $fs, $subdir ) {
				return $fs->dirlist( $subdir, false, false );
			}
		);
		$list_all = self::call(
			static function () use ( $fs, $subdir ) {
				return $fs->dirlist( $subdir, true, false );
			}
		);
		if (
			$list_visible['threw']
			|| $list_all['threw']
			|| ! is_array( $list_visible['value'] )
			|| ! is_array( $list_all['value'] )
			|| isset( $list_visible['value'][ basename( $hidden ) ] )
			|| ! isset( $list_all['value'][ basename( $hidden ) ] )
			|| ! isset( $list_all['value'][ basename( $file ) ] )
		) {
			self::record_failure(
				$failures,
				'WP_Filesystem_Direct.dirlist-hidden-policy',
				array( 'value' => $subdir, 'features' => array( 'direct' ), 'source' => 'sandbox' ),
				array(
					'visible' => self::describe_call( $list_visible ),
					'all'     => self::describe_call( $list_all ),
				)
			);
		}

		$delete_file = self::call(
			static function () use ( $fs, $file ) {
				return $fs->delete( $file, false, 'f' );
			}
		);
		$delete_dir = self::call(
			static function () use ( $fs, $subdir ) {
				return $fs->rmdir( $subdir, true );
			}
		);
		if ( $delete_file['threw'] || true !== $delete_file['value'] || $fs->exists( $file ) || $delete_dir['threw'] || true !== $delete_dir['value'] || $fs->exists( $subdir ) ) {
			self::record_failure(
				$failures,
				'WP_Filesystem_Direct.delete-rmdir-cleanup',
				array( 'value' => $subdir, 'features' => array( 'direct' ), 'source' => 'sandbox' ),
				array(
					'deleteFile' => self::describe_call( $delete_file ),
					'deleteDir'  => self::describe_call( $delete_dir ),
					'fileExists' => $fs->exists( $file ),
					'dirExists'  => $fs->exists( $subdir ),
				)
			);
		}

		return self::row(
			$ctx,
			'filesystem.WP_Filesystem_Direct.sandbox-lifecycle',
			array(
				array(
					'value'    => $leaf,
					'source'   => 'generated',
					'features' => self::filename_features( $leaf ),
				),
			),
			$failures,
			array(
				'sandbox' => self::describe_string( $dir ),
				'leaf'    => self::describe_string( $leaf ),
				'method'  => isset( $fs->method ) ? $fs->method : null,
			)
		);
	}

	private static function check_wp_filesystem_direct_metadata( \ComponentFuzz\FuzzContext $ctx, array $cases, string $dir ): array {
		if ( ! class_exists( 'WP_Filesystem_Direct' ) ) {
			return $ctx->skip( 'filesystem.WP_Filesystem_Direct.metadata-available', 'WP_Filesystem_Direct is unavailable.' );
		}

		$failures = array();
		$fs       = new \WP_Filesystem_Direct( null );
		$leaf     = self::safe_leaf_name( $cases[ $ctx->int( 0, count( $cases ) - 1 ) ]['value'], 'metadata.txt' );
		$subdir   = $dir . DIRECTORY_SEPARATOR . 'metadata-' . $ctx->iteration();
		$file     = $subdir . DIRECTORY_SEPARATOR . $leaf;
		$missing  = $subdir . DIRECTORY_SEPARATOR . 'missing-' . $leaf;
		$contents = "alpha=" . $ctx->seed() . "\n"
			. "beta=" . $ctx->iteration() . "\n"
			. "gamma=" . self::trim_bytes( $ctx->text( 0, 48 ), 48 ) . "\n";
		$mtime    = 946684800 + $ctx->int( 0, 86400 * 365 );
		$atime    = $mtime + $ctx->int( 1, 3600 );
		$case     = array(
			'value'    => $file,
			'source'   => 'generated',
			'features' => array( 'direct', 'metadata' ),
		);

		self::ensure_dir( $subdir );

		$missing_size  = self::call( static function () use ( $fs, $missing ) {
			return $fs->size( $missing );
		} );
		$missing_mtime = self::call( static function () use ( $fs, $missing ) {
			return $fs->mtime( $missing );
		} );
		$missing_atime = self::call( static function () use ( $fs, $missing ) {
			return $fs->atime( $missing );
		} );

		if (
			$missing_size['threw']
			|| $missing_mtime['threw']
			|| $missing_atime['threw']
			|| false !== $missing_size['value']
			|| false !== $missing_mtime['value']
			|| false !== $missing_atime['value']
			|| '0' !== $fs->getchmod( $missing )
			|| $fs->exists( $missing )
		) {
			self::record_failure(
				$failures,
				'WP_Filesystem_Direct.missing-file-metadata-fails-closed',
				$case,
				array(
					'size'    => self::describe_call( $missing_size ),
					'mtime'   => self::describe_call( $missing_mtime ),
					'atime'   => self::describe_call( $missing_atime ),
					'chmod'   => $fs->getchmod( $missing ),
					'exists'  => $fs->exists( $missing ),
					'missing' => self::describe_string( $missing ),
				)
			);
		}

		$put = self::call( static function () use ( $fs, $file, $contents ) {
			return $fs->put_contents( $file, $contents, 0644 );
		} );
		clearstatcache( true, $file );

		$array = self::call( static function () use ( $fs, $file ) {
			return $fs->get_contents_array( $file );
		} );
		if (
			$put['threw']
			|| true !== $put['value']
			|| $array['threw']
			|| ! is_array( $array['value'] )
			|| implode( '', $array['value'] ) !== $contents
			|| $fs->size( $file ) !== strlen( $contents )
			|| ! $fs->is_readable( $file )
			|| ! $fs->is_writable( $file )
		) {
			self::record_failure(
				$failures,
				'WP_Filesystem_Direct.contents-array-size-readability',
				$case,
				array(
					'put'      => self::describe_call( $put ),
					'array'    => self::describe_call( $array ),
					'size'     => $fs->size( $file ),
					'expected' => strlen( $contents ),
					'readable' => $fs->is_readable( $file ),
					'writable' => $fs->is_writable( $file ),
				)
			);
		}

		$touch = self::call( static function () use ( $fs, $file, $mtime, $atime ) {
			return $fs->touch( $file, $mtime, $atime );
		} );
		clearstatcache( true, $file );

		if (
			$touch['threw']
			|| true !== $touch['value']
			|| abs( (int) $fs->mtime( $file ) - $mtime ) > 2
			|| abs( (int) $fs->atime( $file ) - $atime ) > 2
		) {
			self::record_failure(
				$failures,
				'WP_Filesystem_Direct.touch-updates-times',
				$case,
				array(
					'touch'         => self::describe_call( $touch ),
					'expectedMtime' => $mtime,
					'actualMtime'   => $fs->mtime( $file ),
					'expectedAtime' => $atime,
					'actualAtime'   => $fs->atime( $file ),
				)
			);
		}

		$chmod = self::call( static function () use ( $fs, $file ) {
			return $fs->chmod( $file, 0640 );
		} );
		clearstatcache( true, $file );

		if ( $chmod['threw'] || true !== $chmod['value'] || '640' !== $fs->getchmod( $file ) ) {
			self::record_failure(
				$failures,
				'WP_Filesystem_Direct.chmod-getchmod-roundtrip',
				$case,
				array(
					'chmod'  => self::describe_call( $chmod ),
					'actual' => $fs->getchmod( $file ),
				)
			);
		}

		return self::row(
			$ctx,
			'filesystem.WP_Filesystem_Direct.metadata-and-times',
			array( $case ),
			$failures,
			array(
				'sandbox' => self::describe_string( $dir ),
				'leaf'    => self::describe_string( $leaf ),
			)
		);
	}

	private static function check_zip_archive_extraction( \ComponentFuzz\FuzzContext $ctx, string $dir ): array {
		if ( ! class_exists( 'ZipArchive' ) || ! class_exists( 'WP_Filesystem_Direct' ) ) {
			return $ctx->skip(
				'filesystem.zip-archive.available',
				'ZipArchive or WP_Filesystem_Direct is unavailable.'
			);
		}
		self::ensure_filesystem_chmod_constants();

		$failures  = array();
		$zip_file  = $dir . DIRECTORY_SEPARATOR . 'archive-' . $ctx->iteration() . '.zip';
		$bad_file  = $dir . DIRECTORY_SEPARATOR . 'not-a-zip-' . $ctx->iteration() . '.zip';
		$zip_to    = $dir . DIRECTORY_SEPARATOR . 'ziparchive-out';
		$pcl_to    = $dir . DIRECTORY_SEPARATOR . 'pclzip-out';
		$outside   = $dir . DIRECTORY_SEPARATOR . 'escape.txt';
		$root_name = 'fixture-' . strtolower( $ctx->identifier( 5, 8 ) );
		$fixture   = array(
			$root_name . '/readme.txt'       => "readme seed=" . $ctx->seed() . "\n",
			$root_name . '/nested/code.php' => "<?php\n// fixture " . $ctx->iteration() . "\n",
			$root_name . '/nested/data.json' => '{"seed":' . $ctx->seed() . ',"iteration":' . $ctx->iteration() . '}',
			$root_name . '/empty/'           => null,
			'__MACOSX/._' . $root_name       => 'metadata should not extract',
			'../escape.txt'                  => 'traversal should not extract',
		);
		$expected  = array(
			$root_name . '/nested/code.php'  => $fixture[ $root_name . '/nested/code.php' ],
			$root_name . '/nested/data.json' => $fixture[ $root_name . '/nested/data.json' ],
			$root_name . '/readme.txt'       => $fixture[ $root_name . '/readme.txt' ],
		);
		ksort( $expected );

		$created = self::create_zip_fixture( $zip_file, $fixture );
		file_put_contents( $bad_file, "not a zip\n" . $ctx->bytes( 0, 12 ) );

		if ( ! $created ) {
			return $ctx->skip(
				'filesystem.zip-archive.fixture-created',
				'Could not create a local ZipArchive fixture.',
				array( 'zip' => self::describe_string( $zip_file ) )
			);
		}

		$valid_default = self::call( static function () use ( $zip_file ) {
			return \wp_zip_file_is_valid( $zip_file );
		} );
		$invalid_default = self::call( static function () use ( $bad_file ) {
			return \wp_zip_file_is_valid( $bad_file );
		} );

		$pclzip_valid_filter = static function () {
			return false;
		};
		\add_filter( 'unzip_file_use_ziparchive', $pclzip_valid_filter, 20 );
		try {
			$valid_pclzip = self::call( static function () use ( $zip_file ) {
				return \wp_zip_file_is_valid( $zip_file );
			} );
			$invalid_pclzip = self::call( static function () use ( $bad_file ) {
				return \wp_zip_file_is_valid( $bad_file );
			} );
		} finally {
			$pclzip_valid_removed = \remove_filter( 'unzip_file_use_ziparchive', $pclzip_valid_filter, 20 );
		}

		if (
			$valid_default['threw']
			|| $invalid_default['threw']
			|| $valid_pclzip['threw']
			|| $invalid_pclzip['threw']
			|| true !== $valid_default['value']
			|| false !== $invalid_default['value']
			|| true !== $valid_pclzip['value']
			|| false !== $invalid_pclzip['value']
			|| ! $pclzip_valid_removed
		) {
			self::record_failure(
				$failures,
				'wp_zip_file_is_valid.ZipArchive-PclZip-valid-invalid-parity',
				array( 'value' => $zip_file, 'source' => 'generated', 'features' => array( 'zip' ) ),
				array(
					'validDefault'    => self::describe_call( $valid_default ),
					'invalidDefault'  => self::describe_call( $invalid_default ),
					'validPclZip'     => self::describe_call( $valid_pclzip ),
					'invalidPclZip'   => self::describe_call( $invalid_pclzip ),
					'pclFilterRemove' => $pclzip_valid_removed ?? null,
				)
			);
		}

		$pre_events       = array();
		$result_events    = array();
		$zip_route_events = array();
		$pre_filter       = static function ( $result, string $file, string $to, array $needed_dirs, float $required_space ) use ( &$pre_events ) {
			$pre_events[] = array(
				'file'          => $file,
				'to'            => $to,
				'neededDirs'    => $needed_dirs,
				'requiredSpace' => $required_space,
			);

			return $result;
		};
		$result_filter    = static function ( $result, string $file, string $to, array $needed_dirs, float $required_space ) use ( &$result_events ) {
			$result_events[] = array(
				'result'        => true === $result ? true : ( \is_wp_error( $result ) ? $result->get_error_code() : $result ),
				'file'          => $file,
				'to'            => $to,
				'neededDirs'    => $needed_dirs,
				'requiredSpace' => $required_space,
			);

			return $result;
		};
		$zip_route_filter = static function ( $use_ziparchive ) use ( &$zip_route_events ) {
			$selected_route     = 0 === count( $zip_route_events );
			$zip_route_events[] = $selected_route;

			return $selected_route;
		};

		$previous_filesystem      = $GLOBALS['wp_filesystem'] ?? null;
		$had_previous_filesystem  = array_key_exists( 'wp_filesystem', $GLOBALS );
		$GLOBALS['wp_filesystem'] = new \WP_Filesystem_Direct( null );
		\add_filter( 'pre_unzip_file', $pre_filter, 10, 5 );
		\add_filter( 'unzip_file', $result_filter, 10, 5 );
		\add_filter( 'unzip_file_use_ziparchive', $zip_route_filter, 10 );

		try {
			$zip_result = self::call( static function () use ( $zip_file, $zip_to ) {
				return \unzip_file( $zip_file, $zip_to );
			} );
			$pcl_result = self::call( static function () use ( $zip_file, $pcl_to ) {
				return \unzip_file( $zip_file, $pcl_to );
			} );
		} finally {
			$zip_route_removed = \remove_filter( 'unzip_file_use_ziparchive', $zip_route_filter, 10 );
			$result_removed    = \remove_filter( 'unzip_file', $result_filter, 10 );
			$pre_removed       = \remove_filter( 'pre_unzip_file', $pre_filter, 10 );
			if ( $had_previous_filesystem ) {
				$GLOBALS['wp_filesystem'] = $previous_filesystem;
			} else {
				unset( $GLOBALS['wp_filesystem'] );
			}
		}

		$zip_manifest = self::directory_file_manifest( $zip_to );
		$pcl_manifest = self::directory_file_manifest( $pcl_to );

		if (
			$zip_result['threw']
			|| $pcl_result['threw']
			|| true !== $zip_result['value']
			|| true !== $pcl_result['value']
			|| $zip_manifest !== $expected
			|| $pcl_manifest !== $expected
			|| file_exists( $outside )
			|| is_dir( $zip_to . DIRECTORY_SEPARATOR . '__MACOSX' )
			|| is_dir( $pcl_to . DIRECTORY_SEPARATOR . '__MACOSX' )
			|| 2 !== count( $pre_events )
			|| 2 !== count( $result_events )
			|| array( true, false ) !== $zip_route_events
			|| ! $pre_removed
			|| ! $result_removed
			|| ! $zip_route_removed
			|| \has_filter( 'pre_unzip_file', $pre_filter )
			|| \has_filter( 'unzip_file', $result_filter )
			|| \has_filter( 'unzip_file_use_ziparchive', $zip_route_filter )
		) {
			self::record_failure(
				$failures,
				'unzip_file.ZipArchive-PclZip-parity-skips-invalid-paths-and-restores-filters',
				array( 'value' => $zip_file, 'source' => 'generated', 'features' => array( 'zip', 'traversal', 'macosx' ) ),
				array(
					'zipResult'      => self::describe_call( $zip_result ),
					'pclResult'      => self::describe_call( $pcl_result ),
					'expected'       => array_keys( $expected ),
					'zipManifest'    => $zip_manifest,
					'pclManifest'    => $pcl_manifest,
					'outsideExists'  => file_exists( $outside ),
					'preEvents'      => $pre_events,
					'resultEvents'   => $result_events,
					'zipRouteEvents' => $zip_route_events,
					'filtersRemoved' => array(
						'pre'    => $pre_removed ?? null,
						'result' => $result_removed ?? null,
						'route'  => $zip_route_removed ?? null,
					),
				)
			);
		}

		return self::row(
			$ctx,
			'filesystem.zip-validity-and-unzip-parity',
			array(
				array(
					'value'    => $zip_file,
					'source'   => 'generated',
					'features' => array( 'zip', 'traversal', 'macosx' ),
				),
			),
			$failures,
			array(
				'zip'            => self::describe_string( $zip_file ),
				'rootName'       => $root_name,
				'extractedFiles' => array_keys( $expected ),
			)
		);
	}

	private static function check_copy_move_dir_contracts( \ComponentFuzz\FuzzContext $ctx, array $cases, string $dir ): array {
		if ( ! class_exists( 'WP_Filesystem_Direct' ) ) {
			return $ctx->skip( 'filesystem.copy-move.WP_Filesystem_Direct.available', 'WP_Filesystem_Direct is unavailable.' );
		}
		self::ensure_filesystem_chmod_constants();

		$failures      = array();
		$leaf          = self::safe_leaf_name( $cases[ $ctx->int( 0, count( $cases ) - 1 ) ]['value'], 'payload.txt' );
		$from          = $dir . DIRECTORY_SEPARATOR . 'copy-from';
		$to            = $dir . DIRECTORY_SEPARATOR . 'copy-to';
		$missing       = $dir . DIRECTORY_SEPARATOR . 'missing-source';
		$move_from     = $dir . DIRECTORY_SEPARATOR . 'move-from';
		$move_to       = $dir . DIRECTORY_SEPARATOR . 'move-to';
		$fallback_from = $dir . DIRECTORY_SEPARATOR . 'fallback-from';
		$fallback_to   = $dir . DIRECTORY_SEPARATOR . 'fallback-to';

		self::write_tree_fixture(
			$from,
			array(
				$leaf                    => 'copy payload ' . $ctx->seed(),
				'code.php'               => "<?php\n// copy " . $ctx->iteration() . "\n",
				'skip-me.txt'            => 'skip root',
				'nested/keep.txt'        => 'nested keep',
				'nested/skip-child.txt'  => 'skip child',
			)
		);
		self::write_tree_fixture(
			$move_from,
			array(
				'fresh.php'       => "<?php\n// moved\n",
				'nested/file.txt' => 'move nested',
			)
		);
		self::write_tree_fixture(
			$move_to,
			array(
				'stale.txt' => 'stale destination',
			)
		);
		self::write_tree_fixture(
			$fallback_from,
			array(
				'fallback.php'        => "<?php\n// fallback\n",
				'nested/fallback.txt' => 'fallback nested',
			)
		);

		$previous_filesystem      = $GLOBALS['wp_filesystem'] ?? null;
		$had_previous_filesystem  = array_key_exists( 'wp_filesystem', $GLOBALS );
		$GLOBALS['wp_filesystem'] = new \WP_Filesystem_Direct( null );

		try {
			$missing_result = self::call( static function () use ( $missing, $to ) {
				return \copy_dir( $missing, $to . '-missing' );
			} );
			$copy_result = self::call( static function () use ( $from, $to ) {
				return \copy_dir( $from, $to, array( 'skip-me.txt', 'nested/skip-child.txt' ) );
			} );
			$same_result = self::call( static function () use ( $move_from ) {
				return \move_dir( $move_from, $move_from );
			} );
			$exists_result = self::call( static function () use ( $move_from, $move_to ) {
				return \move_dir( $move_from, $move_to, false );
			} );
			$overwrite_result = self::call( static function () use ( $move_from, $move_to ) {
				return \move_dir( $move_from, $move_to, true );
			} );

			$fallback_fs = new class( null ) extends \WP_Filesystem_Direct {
				public array $move_calls = array();

				public function move( $source, $destination, $overwrite = false ) {
					$this->move_calls[] = array(
						'source'      => $source,
						'destination' => $destination,
						'overwrite'   => $overwrite,
					);

					return false;
				}
			};
			$GLOBALS['wp_filesystem'] = $fallback_fs;
			$fallback_result = self::call( static function () use ( $fallback_from, $fallback_to ) {
				return \move_dir( $fallback_from, $fallback_to, false );
			} );
			$fallback_move_calls = $fallback_fs->move_calls;
		} finally {
			if ( $had_previous_filesystem ) {
				$GLOBALS['wp_filesystem'] = $previous_filesystem;
			} else {
				unset( $GLOBALS['wp_filesystem'] );
			}
		}

		$copy_expected = array(
			'code.php'        => "<?php\n// copy " . $ctx->iteration() . "\n",
			$leaf             => 'copy payload ' . $ctx->seed(),
			'nested/keep.txt' => 'nested keep',
		);
		$source_expected = array(
			'code.php'              => "<?php\n// copy " . $ctx->iteration() . "\n",
			$leaf                   => 'copy payload ' . $ctx->seed(),
			'nested/keep.txt'       => 'nested keep',
			'nested/skip-child.txt' => 'skip child',
			'skip-me.txt'           => 'skip root',
		);
		$move_expected = array(
			'fresh.php'       => "<?php\n// moved\n",
			'nested/file.txt' => 'move nested',
		);
		$fallback_expected = array(
			'fallback.php'        => "<?php\n// fallback\n",
			'nested/fallback.txt' => 'fallback nested',
		);
		ksort( $copy_expected );
		ksort( $source_expected );
		ksort( $move_expected );
		ksort( $fallback_expected );

		$missing_error_code = \is_wp_error( $missing_result['value'] ?? null ) ? $missing_result['value']->get_error_code() : null;
		$same_error_code    = \is_wp_error( $same_result['value'] ?? null ) ? $same_result['value']->get_error_code() : null;
		$exists_error_code  = \is_wp_error( $exists_result['value'] ?? null ) ? $exists_result['value']->get_error_code() : null;

		if (
			$missing_result['threw']
			|| 'dirlist_failed_copy_dir' !== $missing_error_code
			|| $copy_result['threw']
			|| true !== $copy_result['value']
			|| self::directory_file_manifest( $to ) !== $copy_expected
			|| self::directory_file_manifest( $from ) !== $source_expected
		) {
			self::record_failure(
				$failures,
				'copy_dir.missing-source-error-skip-list-and-source-preservation',
				array( 'value' => $from, 'source' => 'generated', 'features' => array( 'copy-dir', 'skip-list' ) ),
				array(
					'missingResult' => self::describe_call( $missing_result ),
					'missingCode'   => $missing_error_code,
					'copyResult'    => self::describe_call( $copy_result ),
					'expected'      => $copy_expected,
					'actual'        => self::directory_file_manifest( $to ),
					'source'        => self::directory_file_manifest( $from ),
				)
			);
		}

		if (
			$same_result['threw']
			|| 'source_destination_same_move_dir' !== $same_error_code
			|| $exists_result['threw']
			|| 'destination_already_exists_move_dir' !== $exists_error_code
			|| $overwrite_result['threw']
			|| true !== $overwrite_result['value']
			|| is_dir( $move_from )
			|| self::directory_file_manifest( $move_to ) !== $move_expected
			|| $fallback_result['threw']
			|| true !== $fallback_result['value']
			|| is_dir( $fallback_from )
			|| self::directory_file_manifest( $fallback_to ) !== $fallback_expected
			|| 1 !== count( $fallback_move_calls )
		) {
			self::record_failure(
				$failures,
				'move_dir.same-destination-existing-overwrite-and-copy-fallback-contracts',
				array( 'value' => $move_from, 'source' => 'generated', 'features' => array( 'move-dir', 'fallback-copy' ) ),
				array(
					'sameResult'       => self::describe_call( $same_result ),
					'sameCode'         => $same_error_code,
					'existsResult'     => self::describe_call( $exists_result ),
					'existsCode'       => $exists_error_code,
					'overwriteResult'  => self::describe_call( $overwrite_result ),
					'moveManifest'     => self::directory_file_manifest( $move_to ),
					'fallbackResult'   => self::describe_call( $fallback_result ),
					'fallbackManifest' => self::directory_file_manifest( $fallback_to ),
					'fallbackMoves'    => $fallback_move_calls,
				)
			);
		}

		return self::row(
			$ctx,
			'filesystem.copy-dir-and-move-dir-contracts',
			array(
				array(
					'value'    => $leaf,
					'source'   => 'generated',
					'features' => self::filename_features( $leaf ),
				),
			),
			$failures,
			array(
				'sandbox' => self::describe_string( $dir ),
				'leaf'    => self::describe_string( $leaf ),
			)
		);
	}

	private static function path_cases( \ComponentFuzz\FuzzContext $ctx ): array {
		$cases = array();
		foreach ( self::path_corpus() as $path ) {
			self::add_case( $cases, self::trim_bytes( $path, self::MAX_PATH_BYTES ), 'corpus', self::path_features( $path ) );
		}

		for ( $i = 0; $i < self::GENERATED_PATH_CASES; ++$i ) {
			$case_ctx = $ctx->fork( 'filesystem-path-' . $i );
			$path     = self::generated_path( $case_ctx );
			self::add_case( $cases, self::trim_bytes( $path, self::MAX_PATH_BYTES ), 'generated', self::path_features( $path ) );
		}

		return array_values( $cases );
	}

	private static function filename_cases( \ComponentFuzz\FuzzContext $ctx ): array {
		$cases = array();
		foreach ( self::filename_corpus() as $filename ) {
			self::add_case( $cases, self::trim_bytes( $filename, self::MAX_FILENAME_BYTES ), 'corpus', self::filename_features( $filename ) );
		}

		for ( $i = 0; $i < self::GENERATED_FILENAME_CASES; ++$i ) {
			$case_ctx = $ctx->fork( 'filesystem-filename-' . $i );
			$filename = self::generated_filename( $case_ctx );
			self::add_case( $cases, self::trim_bytes( $filename, self::MAX_FILENAME_BYTES ), 'generated', self::filename_features( $filename ) );
		}

		return array_values( $cases );
	}

	private static function path_corpus(): array {
		return array(
			'',
			'.',
			'./',
			'../',
			'../../wp-config.php',
			'a/../b',
			'a/../',
			'/absolute/path/file.php',
			'\\absolute\\path\\file.php',
			'C:\\Windows\\System32\\drivers\\etc\\hosts',
			'c:/Temp/file.txt',
			'folder//sub///file.txt',
			'folder\\sub\\file.txt',
			'//server//share///file.txt',
			'file://C:\\temp\\file.txt',
			'php://memory',
			'bad' . "\0" . 'path.php',
			"line\nbreak/file.txt",
			"tab\tpath/value.txt",
			'wp-content/plugins/../../themes/theme/style.css',
			'.hidden/.env',
			'CON/AUX.txt',
			'archive.tar.gz',
			'dir/' . str_repeat( 'a', 120 ) . '/' . str_repeat( 'b', 80 ) . '.txt',
			"unicode/r\u{00E9}sum\u{00E9}/\u{4E2D}\u{6587}/snowman-\u{2603}.txt",
		);
	}

	private static function filename_corpus(): array {
		return array(
			'',
			'.',
			'..',
			'../escape.php',
			'..\\escape.php',
			'/absolute/name.txt',
			'\\absolute\\name.txt',
			'photo.JPG',
			'photo.jpg',
			'archive.tar.gz',
			'file.php.jpg',
			'shell.phtml.txt',
			'double..dots...jpg',
			'bad' . "\0" . 'name.php',
			"line\nbreak.txt",
			"tab\tname.csv",
			'r' . "\u{00E9}" . 'sum' . "\u{00E9}" . '.pdf',
			"\u{4E2D}\u{6587}.txt",
			'.hidden',
			'CON',
			'CON.txt',
			'AUX.log',
			'NUL',
			'COM1.txt',
			'LPT9.prn',
			'name-150x150.png',
			'thumb-rotated.webp',
			str_repeat( 'a', 140 ) . '.txt',
			' spaced  name .txt ',
			'semi;colon:name?.txt',
			'100%+file#name&copy.txt',
			'invalid-' . "\xff\xfe" . '.jpg',
		);
	}

	private static function generated_path( \ComponentFuzz\FuzzContext $ctx ): string {
		$components = array();
		$count      = $ctx->int( 1, 6 );

		for ( $i = 0; $i < $count; ++$i ) {
			$components[] = self::generated_component( $ctx, true );
		}

		$separator = $ctx->choice( array( '/', '\\', '//', '\\\\', '/\\', '\\/' ) );
		$prefix    = $ctx->choice(
			array(
				'',
				'./',
				'../',
				'../../',
				'/',
				'\\',
				'C:\\',
				'c:/',
				'//server/share/',
				'file://',
				'php://',
			)
		);
		$suffix    = $ctx->choice( array( '', '/', '\\', '//', '/../tail', '/.' ) );

		return $prefix . implode( $separator, $components ) . $suffix;
	}

	private static function generated_filename( \ComponentFuzz\FuzzContext $ctx ): string {
		$base = self::generated_component( $ctx, false );
		$exts = $ctx->choice(
			array(
				array(),
				array( 'txt' ),
				array( 'JPG' ),
				array( 'php', 'jpg' ),
				array( 'phtml', 'txt' ),
				array( 'tar', 'gz' ),
				array( '150x150', 'png' ),
				array( 'rotated', 'webp' ),
				array( 'backup', 'sql' ),
			)
		);

		$filename = $base;
		foreach ( $exts as $ext ) {
			$filename .= '.' . $ext;
		}

		if ( $ctx->bool( 20 ) ) {
			$filename = $ctx->choice( array( '../', '..\\', '/', '\\', './' ) ) . $filename;
		}
		if ( $ctx->bool( 20 ) ) {
			$filename .= $ctx->choice( array( '.', '-', '_', ' ', '..' ) );
		}

		return $filename;
	}

	private static function generated_component( \ComponentFuzz\FuzzContext $ctx, bool $allow_empty ): string {
		$choices = array(
			'safe',
			'wp-content',
			'plugins',
			'..',
			'.',
			'.hidden',
			'CON',
			'PRN',
			'AUX',
			'NUL',
			'COM1',
			'LPT1',
			"r\u{00E9}sum\u{00E9}",
			"\u{4E2D}\u{6587}",
			'snowman-' . "\u{2603}",
			'space name',
			'semi;colon:name?',
			'archive.tar.gz',
			'file.php.jpg',
			'bad' . chr( 0 ) . 'name',
			'ctl' . chr( $ctx->int( 1, 31 ) ) . 'byte',
			'bytes-' . $ctx->bytes( 1, 5 ),
			str_repeat( $ctx->choice( array( 'a', 'b', 'x', '9' ) ), $ctx->int( 48, 120 ) ),
		);

		if ( $allow_empty ) {
			$choices[] = '';
		}

		return $ctx->choice( $choices );
	}

	private static function add_case( array &$cases, string $value, string $source, array $features ): void {
		$key = sha1( $value );
		if ( isset( $cases[ $key ] ) ) {
			return;
		}

		sort( $features );
		$cases[ $key ] = array(
			'value'    => $value,
			'source'   => $source,
			'features' => $features,
		);
	}

	private static function path_features( string $path ): array {
		$features = array();

		if ( false !== strpos( $path, '..' ) ) {
			$features[] = 'traversal';
		}
		if ( false !== strpos( $path, '\\' ) ) {
			$features[] = 'backslash';
		}
		if ( false !== strpos( $path, chr( 0 ) ) ) {
			$features[] = 'nul-byte';
		}
		if ( false !== strpbrk( $path, "\x01\x02\x03\x04\x05\x06\x07\x08\x09\x0A\x0B\x0C\x0D\x0E\x0F\x10\x11\x12\x13\x14\x15\x16\x17\x18\x19\x1A\x1B\x1C\x1D\x1E\x1F\x7F" ) ) {
			$features[] = 'control-byte';
		}
		if ( 1 === preg_match( '/[\x80-\xff]/', $path ) ) {
			$features[] = 'non-ascii-bytes';
		}
		if ( false !== strpos( $path, '//' ) || false !== strpos( $path, '\\\\' ) || false !== strpos( $path, '/\\' ) || false !== strpos( $path, '\\/' ) ) {
			$features[] = 'repeated-separator';
		}
		if ( self::path_is_absolute_like_core( $path ) ) {
			$features[] = 'absolute';
		}
		if ( isset( $path[1] ) && ':' === $path[1] ) {
			$features[] = 'windows-drive';
		}
		if ( 1 === preg_match( '/(^|[\/\\\\])(CON|PRN|AUX|NUL|COM[1-9]|LPT[1-9])([.\/\\\\]|$)/i', $path ) ) {
			$features[] = 'reserved-device';
		}
		if ( 1 === preg_match( '/(^|[\/\\\\])\.[^\/\\\\.]/', $path ) ) {
			$features[] = 'hidden';
		}
		if ( substr_count( basename( str_replace( '\\', '/', $path ) ), '.' ) >= 2 ) {
			$features[] = 'multi-extension';
		}
		if ( strlen( $path ) > 120 ) {
			$features[] = 'long';
		}
		if ( false !== strpos( $path, '://' ) ) {
			$features[] = 'stream-like';
		}

		return array_values( array_unique( $features ) );
	}

	private static function filename_features( string $filename ): array {
		$features = self::path_features( $filename );
		if ( '' !== $filename && '.' === $filename[0] ) {
			$features[] = 'hidden';
		}
		if ( 1 === preg_match( '/^(CON|PRN|AUX|NUL|COM[1-9]|LPT[1-9])(\.|$)/i', basename( str_replace( '\\', '/', $filename ) ) ) ) {
			$features[] = 'reserved-device';
		}

		return array_values( array_unique( $features ) );
	}

	private static function expected_normalized_path( string $path ): string {
		$wrapper = '';
		if ( function_exists( 'wp_is_stream' ) && \wp_is_stream( $path ) ) {
			list( $wrapper, $path ) = explode( '://', $path, 2 );
			$wrapper              .= '://';
		}

		$path = str_replace( '\\', '/', $path );
		$path = (string) preg_replace( '|(?<=.)/+|', '/', $path );
		if ( ':' === substr( $path, 1, 1 ) ) {
			$path = ucfirst( $path );
		}

		return $wrapper . $path;
	}

	private static function expected_path_join( string $base, string $path ): string {
		if ( self::path_is_absolute_like_core( $path ) ) {
			return $path;
		}

		return rtrim( $base, '/' ) . '/' . $path;
	}

	private static function path_is_absolute_like_core( string $path ): bool {
		if ( function_exists( 'path_is_absolute' ) && false === strpos( $path, chr( 0 ) ) ) {
			try {
				return \path_is_absolute( $path );
			} catch ( \Throwable $e ) {
				// Fall through to the string-only approximation below.
			}
		}

		if ( '' === $path || '.' === $path[0] ) {
			return false;
		}

		if ( 1 === preg_match( '#^[a-zA-Z]:\\\\#', $path ) ) {
			return true;
		}

		return '/' === $path[0] || '\\' === $path[0];
	}

	private static function expected_validate_file_code( $file, array $allowed_files ): int {
		if ( ! is_scalar( $file ) || '' === $file ) {
			return 0;
		}

		$file = \wp_normalize_path( (string) $file );
		foreach ( $allowed_files as $index => $allowed_file ) {
			$allowed_files[ $index ] = \wp_normalize_path( $allowed_file );
		}

		if ( '../' === $file ) {
			return 1;
		}

		if ( substr_count( $file, '../' ) > 1 ) {
			return 1;
		}

		if ( false !== strpos( $file, '../' ) && '../' !== substr( $file, -3 ) ) {
			return 1;
		}

		if ( array() !== $allowed_files && ! in_array( $file, $allowed_files, true ) ) {
			return 3;
		}

		if ( ':' === substr( $file, 1, 1 ) ) {
			return 2;
		}

		return 0;
	}

	private static function has_disallowed_duplicate_slashes( string $path ): bool {
		$without_wrapper = $path;
		if ( 1 === preg_match( '#^[A-Za-z0-9+.-]+://#', $without_wrapper, $matches ) ) {
			$without_wrapper = substr( $without_wrapper, strlen( $matches[0] ) );
		}
		if ( str_starts_with( $without_wrapper, '//' ) ) {
			$without_wrapper = substr( $without_wrapper, 2 );
		}

		$length = strlen( $without_wrapper );
		for ( $i = 1; $i < $length; ++$i ) {
			if ( '/' !== $without_wrapper[ $i - 1 ] || '/' !== $without_wrapper[ $i ] ) {
				continue;
			}

			if ( $i >= 2 && "\n" === $without_wrapper[ $i - 2 ] ) {
				++$i;
				continue;
			}

			return true;
		}

		return false;
	}

	private static function has_lowercase_drive_letter( string $path ): bool {
		if ( 1 === preg_match( '#^[A-Za-z0-9+.-]+://#', $path, $matches ) ) {
			$path = substr( $path, strlen( $matches[0] ) );
		}

		return isset( $path[1] ) && ':' === $path[1] && $path[0] >= 'a' && $path[0] <= 'z';
	}

	private static function filename_has_forbidden_output_chars( string $filename ): bool {
		return false !== strpos( $filename, '/' )
			|| false !== strpos( $filename, '\\' )
			|| false !== strpos( $filename, chr( 0 ) )
			|| false !== strpbrk( $filename, "\r\n\t" );
	}

	private static function dangerous_intermediate_extensions( string $filename ): array {
		$parts = explode( '.', $filename );
		if ( count( $parts ) <= 2 ) {
			return array();
		}

		array_shift( $parts );
		array_pop( $parts );

		$dangerous = array();
		foreach ( $parts as $part ) {
			$lower = strtolower( $part );
			if ( in_array( $lower, array( 'php', 'phtm', 'phtml', 'phar', 'js', 'exe', 'bat', 'cmd', 'com', 'scr' ), true ) ) {
				$dangerous[] = $part;
			}
		}

		return $dangerous;
	}

	private static function safe_leaf_name( string $filename, string $fallback ): string {
		$leaf = \sanitize_file_name( $filename );
		if ( '' === $leaf || '.' === $leaf || '..' === $leaf || self::filename_has_forbidden_output_chars( $leaf ) ) {
			$leaf = $fallback;
		}

		return substr( $leaf, 0, 96 );
	}

	private static function make_temp_root( \ComponentFuzz\FuzzContext $ctx ): ?string {
		$base = '/tmp';
		if ( ! is_dir( $base ) || ! is_writable( $base ) ) {
			return null;
		}

		$name = sprintf(
			'component-fuzz-filesystem-%d-%d-%d',
			$ctx->seed(),
			$ctx->iteration(),
			getmypid()
		);

		for ( $i = 0; $i < 20; ++$i ) {
			$path = $base . DIRECTORY_SEPARATOR . $name . ( 0 === $i ? '' : '-' . $i );
			if ( is_dir( $path ) ) {
				continue;
			}
			if ( @mkdir( $path, 0777, true ) && is_dir( $path ) && self::path_is_inside_directory( $path, $base ) ) {
				return $path;
			}
		}

		return null;
	}

	private static function ensure_dir( string $dir ): void {
		if ( is_dir( $dir ) ) {
			return;
		}

		if ( ! @mkdir( $dir, 0777, true ) && ! is_dir( $dir ) ) {
			throw new \RuntimeException( "Could not create directory: {$dir}" );
		}
	}

	private static function remove_dir_recursive( string $path ): bool {
		if ( ! self::path_is_inside_directory( $path, '/tmp' ) ) {
			return false;
		}
		if ( ! file_exists( $path ) ) {
			return true;
		}
		if ( is_file( $path ) || is_link( $path ) ) {
			return @unlink( $path );
		}
		if ( ! is_dir( $path ) ) {
			return false;
		}

		$entries = scandir( $path );
		if ( false === $entries ) {
			return false;
		}

		$ok = true;
		foreach ( $entries as $entry ) {
			if ( '.' === $entry || '..' === $entry ) {
				continue;
			}
			$child = $path . DIRECTORY_SEPARATOR . $entry;
			if ( ! self::remove_dir_recursive( $child ) ) {
				$ok = false;
			}
		}

		return @rmdir( $path ) && $ok;
	}

	private static function path_is_inside_directory( string $path, string $directory ): bool {
		$directory_real = realpath( $directory );
		if ( false === $directory_real ) {
			return false;
		}

		$path_real = realpath( $path );
		if ( false === $path_real ) {
			$parent = realpath( dirname( $path ) );
			if ( false === $parent ) {
				return false;
			}
			$path_real = $parent . DIRECTORY_SEPARATOR . basename( $path );
		}

		$directory_norm = rtrim( \wp_normalize_path( $directory_real ), '/' ) . '/';
		$path_norm      = \wp_normalize_path( $path_real );

		return $path_norm === rtrim( $directory_norm, '/' ) || str_starts_with( $path_norm, $directory_norm );
	}

	private static function normalized_list_files_result( $files ): array {
		if ( ! is_array( $files ) ) {
			return array();
		}

		$normalized = array();
		foreach ( $files as $file ) {
			$normalized[] = \wp_normalize_path( $file );
		}
		sort( $normalized );

		return $normalized;
	}

	private static function ensure_filesystem_chmod_constants(): void {
		if ( ! defined( 'FS_CHMOD_DIR' ) ) {
			define( 'FS_CHMOD_DIR', ( fileperms( ABSPATH ) & 0777 | 0755 ) );
		}
		if ( ! defined( 'FS_CHMOD_FILE' ) ) {
			define( 'FS_CHMOD_FILE', ( fileperms( ABSPATH . 'index.php' ) & 0777 | 0644 ) );
		}
	}

	private static function create_zip_fixture( string $zip_file, array $entries ): bool {
		$zip = new \ZipArchive();
		if ( true !== $zip->open( $zip_file, \ZipArchive::CREATE | \ZipArchive::OVERWRITE ) ) {
			return false;
		}

		foreach ( $entries as $name => $contents ) {
			if ( null === $contents ) {
				if ( ! $zip->addEmptyDir( $name ) ) {
					$zip->close();
					return false;
				}
				continue;
			}

			if ( ! $zip->addFromString( $name, $contents ) ) {
				$zip->close();
				return false;
			}
		}

		return true === $zip->close();
	}

	private static function write_tree_fixture( string $root, array $files ): void {
		foreach ( $files as $relative => $contents ) {
			$path = $root . DIRECTORY_SEPARATOR . str_replace( '/', DIRECTORY_SEPARATOR, $relative );
			self::ensure_dir( dirname( $path ) );
			file_put_contents( $path, $contents );
		}
	}

	private static function directory_file_manifest( string $root ): array {
		if ( ! is_dir( $root ) ) {
			return array();
		}

		$root     = rtrim( $root, DIRECTORY_SEPARATOR );
		$manifest = array();
		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $root, \FilesystemIterator::SKIP_DOTS ),
			\RecursiveIteratorIterator::LEAVES_ONLY
		);

		foreach ( $iterator as $file ) {
			if ( ! $file->isFile() ) {
				continue;
			}

			$relative              = substr( $file->getPathname(), strlen( $root ) + 1 );
			$relative              = str_replace( DIRECTORY_SEPARATOR, '/', $relative );
			$manifest[ $relative ] = file_get_contents( $file->getPathname() );
		}

		ksort( $manifest );

		return $manifest;
	}

	private static function row( \ComponentFuzz\FuzzContext $ctx, string $invariant, array $cases, array $failures, array $extra = array() ): array {
		$features = array();
		$sources  = array();
		foreach ( $cases as $case ) {
			$sources[ $case['source'] ] = ( $sources[ $case['source'] ] ?? 0 ) + 1;
			foreach ( $case['features'] as $feature ) {
				$features[ $feature ] = true;
			}
		}
		$features = array_keys( $features );
		sort( $features );
		ksort( $sources );

		return $ctx->result(
			$invariant,
			array() === $failures,
			$extra + array(
				'cases'        => count( $cases ),
				'sources'      => $sources,
				'features'     => $features,
				'sampleInputs' => self::sample_inputs( $cases ),
				'failureCount' => count( $failures ),
				'firstFailure' => $failures[0] ?? null,
			)
		);
	}

	private static function record_failure( array &$failures, string $invariant, array $case, array $details = array() ): void {
		if ( count( $failures ) >= self::MAX_FAILURES ) {
			return;
		}

		$failures[] = array(
			'invariant' => $invariant,
			'input'     => self::describe_string( $case['value'] ),
			'source'    => $case['source'],
			'features'  => $case['features'],
			'details'   => $details,
		);
	}

	private static function sample_inputs( array $cases ): array {
		$samples = array();
		foreach ( array_slice( $cases, 0, 8 ) as $case ) {
			$samples[] = array(
				'input'    => self::describe_string( $case['value'] ),
				'source'   => $case['source'],
				'features' => $case['features'],
			);
		}

		return $samples;
	}

	private static function mixed_case_sample( array $cases, int $corpus_limit, int $generated_limit ): array {
		$selected = array();
		$seen     = array();
		$limits   = array(
			'corpus'    => $corpus_limit,
			'generated' => $generated_limit,
		);
		$counts   = array(
			'corpus'    => 0,
			'generated' => 0,
		);

		foreach ( $cases as $case ) {
			$source = $case['source'];
			if ( ! isset( $limits[ $source ] ) || $counts[ $source ] >= $limits[ $source ] ) {
				continue;
			}

			$key = sha1( $source . ':' . $case['value'] );
			if ( isset( $seen[ $key ] ) ) {
				continue;
			}

			$selected[]  = $case;
			$seen[ $key ] = true;
			++$counts[ $source ];
		}

		return $selected;
	}

	private static function call( callable $callback ): array {
		try {
			return array(
				'threw' => false,
				'value' => $callback(),
			);
		} catch ( \Throwable $e ) {
			return array(
				'threw'    => true,
				'value'    => null,
				'throwable' => self::describe_throwable( $e ),
			);
		}
	}

	private static function describe_call( array $call ): array {
		if ( ! empty( $call['threw'] ) ) {
			return array(
				'threw'    => true,
				'throwable' => $call['throwable'] ?? null,
			);
		}

		return array(
			'threw' => false,
			'value' => self::describe_value( $call['value'] ),
		);
	}

	private static function describe_value( $value ) {
		if ( is_string( $value ) ) {
			return self::describe_string( $value );
		}
		if ( is_array( $value ) ) {
			$out = array();
			foreach ( array_slice( $value, 0, 12, true ) as $key => $item ) {
				$out[ $key ] = self::describe_value( $item );
			}
			if ( count( $value ) > 12 ) {
				$out['...'] = count( $value ) - 12;
			}
			return $out;
		}
		if ( is_object( $value ) ) {
			return '[object ' . get_class( $value ) . ']';
		}

		return $value;
	}

	private static function describe_string( string $value ): array {
		return array(
			'bytes'   => strlen( $value ),
			'preview' => self::escape_bytes( $value ),
			'sha1'    => sha1( $value ),
		);
	}

	private static function describe_throwable( \Throwable $e ): array {
		return array(
			'class'   => get_class( $e ),
			'message' => self::escape_bytes( $e->getMessage() ),
		);
	}

	private static function escape_bytes( string $value, int $limit = 160 ): string {
		$out    = '';
		$length = strlen( $value );
		$shown  = min( $length, $limit );

		for ( $i = 0; $i < $shown; ++$i ) {
			$byte = ord( $value[ $i ] );
			if ( $byte >= 0x20 && $byte <= 0x7e && 0x5c !== $byte ) {
				$out .= chr( $byte );
			} elseif ( 0x5c === $byte ) {
				$out .= '\\\\';
			} else {
				$out .= sprintf( '\\x%02X', $byte );
			}
		}

		if ( $length > $limit ) {
			$out .= '...';
		}

		return $out;
	}

	private static function trim_bytes( string $value, int $limit ): string {
		if ( strlen( $value ) <= $limit ) {
			return $value;
		}

		return substr( $value, 0, $limit );
	}

	private static function snapshot_globals(): array {
		$snapshot = array();
		foreach ( array( 'wp_filter', 'wp_actions', 'wp_filters', 'wp_current_filter', 'wp_filesystem' ) as $name ) {
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
				$GLOBALS[ $name ] = $entry['value'];
			} else {
				unset( $GLOBALS[ $name ] );
			}
		}
	}

	private static function clone_value( $value ) {
		if ( is_object( $value ) ) {
			return clone $value;
		}
		if ( is_array( $value ) ) {
			$copy = array();
			foreach ( $value as $key => $item ) {
				$copy[ $key ] = self::clone_value( $item );
			}
			return $copy;
		}

		return $value;
	}
}
