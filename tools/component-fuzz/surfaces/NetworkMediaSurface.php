<?php
namespace ComponentFuzz\Surfaces;

final class NetworkMediaSurface {
	public const NAME = 'network-media';

	private const MAX_FAILURES = 64;

	private static $sideload_upload_root = null;

	public static function run( \ComponentFuzz\FuzzContext $ctx ): array {
		$seed = self::seed_from_context( $ctx );
		$rng  = self::rng( $seed );

		$result = array(
			'surface'        => self::NAME,
			'ok'             => true,
			'seed'           => $seed,
			'caseCount'      => 0,
			'apiCalls'       => 0,
			'assertions'     => 0,
			'failureCount'   => 0,
			'failures'       => array(),
			'features'       => array(),
			'skipped'        => array(),
			'temporaryFiles' => array(
				'root'    => null,
				'cleaned' => false,
			),
		);

		$temp_root = self::make_temp_root( $seed, $result );
		$result['temporaryFiles']['root'] = $temp_root;

		try {
			self::exercise_url_apis( $rng, $result );
			self::exercise_url_scheme_apis( $rng, $result );
			self::exercise_path_apis( $rng, $result );
			self::exercise_filename_apis( $rng, $result );
			self::exercise_multisite_quota_apis( $rng, $result );
			self::exercise_multisite_upload_policy_apis( $rng, $result );

			if ( null === $temp_root ) {
				self::skip_once( $result, 'temporary-files', 'Could not create an isolated directory under sys_get_temp_dir().' );
			} else {
				self::exercise_multisite_check_upload_size_apis( $rng, $result, $temp_root );
				self::exercise_filetype_and_unique_apis( $rng, $result, $temp_root );
				self::exercise_sideload_helpers( $rng, $result, $temp_root );
			}
		} finally {
			if ( null !== $temp_root ) {
				self::remove_dir_recursive( $temp_root );
				$result['temporaryFiles']['cleaned'] = ! is_dir( $temp_root );
			}
		}

		$result['ok'] = 0 === $result['failureCount'];
		$features     = array_keys( $result['features'] );
		sort( $features );
		$result['features'] = $features;
		ksort( $result['skipped'] );

		return $result;
	}

	public static function filter_sideload_upload_dir( array $uploads ): array {
		if ( null === self::$sideload_upload_root ) {
			return $uploads;
		}

		$subdir = isset( $uploads['subdir'] ) ? (string) $uploads['subdir'] : '';
		$base   = rtrim( self::$sideload_upload_root, '/\\' );
		$url    = 'http://example.test/component-fuzz-network';

		$uploads['basedir'] = $base;
		$uploads['baseurl'] = $url;
		$uploads['path']    = $base . $subdir;
		$uploads['url']     = $url . $subdir;
		$uploads['error']   = false;

		return $uploads;
	}

	private static function exercise_url_apis( array &$rng, array &$result ): void {
		$urls = self::url_cases( $rng );

		if ( ! function_exists( 'wp_parse_url' ) ) {
			self::skip_once( $result, 'wp_parse_url', 'Function is unavailable.' );
		}
		if ( ! function_exists( 'sanitize_url' ) ) {
			self::skip_once( $result, 'sanitize_url', 'Function is unavailable.' );
		}
		if ( ! function_exists( 'esc_url' ) ) {
			self::skip_once( $result, 'esc_url', 'Function is unavailable.' );
		}
		if ( ! function_exists( 'wp_http_validate_url' ) ) {
			self::skip_once( $result, 'wp_http_validate_url', 'Function is unavailable.' );
		}

		foreach ( $urls as $url ) {
			++$result['caseCount'];
			self::feature( $result, 'url' );

			if ( function_exists( 'wp_parse_url' ) ) {
				$full = self::call_api(
					$result,
					'wp_parse_url',
					$url,
					static function () use ( $url ) {
						return wp_parse_url( $url );
					}
				);

				if ( $full['ok'] ) {
					$parts = $full['value'];
					self::check_invariant(
						$result,
						false === $parts || is_array( $parts ),
						'wp_parse_url:return-shape',
						$url,
						array( 'actual' => $parts )
					);

					self::check_parse_url_components( $url, $parts, $result );

					if ( is_array( $parts ) && self::is_reconstructable_url_parts( $parts ) ) {
						$rebuilt = self::rebuild_url( $parts );
						$round   = self::call_api(
							$result,
							'wp_parse_url.reconstructed',
							$rebuilt,
							static function () use ( $rebuilt ) {
								return wp_parse_url( $rebuilt );
							}
						);

						if ( $round['ok'] ) {
							self::check_invariant(
								$result,
								is_array( $round['value'] ) && self::same_url_parts( $parts, $round['value'] ),
								'wp_parse_url:reconstructed-components',
								$url,
								array(
									'rebuilt' => $rebuilt,
									'parsed'  => $parts,
									'round'   => $round['value'],
								)
							);
						}
					}
				}
			}

			if ( function_exists( 'sanitize_url' ) ) {
				$sanitized = self::call_api(
					$result,
					'sanitize_url',
					$url,
					static function () use ( $url ) {
						return sanitize_url( $url );
					}
				);

				if ( $sanitized['ok'] ) {
					self::check_invariant(
						$result,
						is_string( $sanitized['value'] ),
						'sanitize_url:return-string',
						$url,
						array( 'actual' => $sanitized['value'] )
					);

					if ( is_string( $sanitized['value'] ) ) {
						$again = self::call_api(
							$result,
							'sanitize_url.idempotent',
							$sanitized['value'],
							static function () use ( $sanitized ) {
								return sanitize_url( $sanitized['value'] );
							}
						);

						if ( $again['ok'] ) {
							self::check_invariant(
								$result,
								$again['value'] === $sanitized['value'],
								'sanitize_url:idempotent',
								$url,
								array(
									'first'  => $sanitized['value'],
									'second' => $again['value'],
								)
							);
						}

						self::check_invariant(
							$result,
							false === strpos( $sanitized['value'], chr( 0 ) ) && false === strpbrk( $sanitized['value'], "\r\n" ),
							'sanitize_url:no-nul-or-newline',
							$url,
							array( 'sanitized' => $sanitized['value'] )
						);
					}
				}
			}

			if ( function_exists( 'esc_url' ) ) {
				$escaped = self::call_api(
					$result,
					'esc_url',
					$url,
					static function () use ( $url ) {
						return esc_url( $url );
					}
				);

				if ( $escaped['ok'] ) {
					self::check_invariant(
						$result,
						is_string( $escaped['value'] ),
						'esc_url:return-string',
						$url,
						array( 'actual' => $escaped['value'] )
					);

					if ( is_string( $escaped['value'] ) ) {
						self::check_invariant(
							$result,
							false === strpos( $escaped['value'], chr( 0 ) ) && false === strpbrk( $escaped['value'], "\r\n" ),
							'esc_url:no-nul-or-newline',
							$url,
							array( 'escaped' => $escaped['value'] )
						);
					}
				}
			}

			if ( function_exists( 'wp_http_validate_url' ) ) {
				if ( ! self::is_http_validate_probe_safe( $url ) ) {
					self::feature( $result, 'wp_http_validate_url:dns-skipped' );
					continue;
				}

				$validated = self::call_api(
					$result,
					'wp_http_validate_url',
					$url,
					static function () use ( $url ) {
						return wp_http_validate_url( $url );
					}
				);

				if ( $validated['ok'] ) {
					self::check_invariant(
						$result,
						false === $validated['value'] || is_string( $validated['value'] ),
						'wp_http_validate_url:return-shape',
						$url,
						array( 'actual' => $validated['value'] )
					);

					if ( is_string( $validated['value'] ) ) {
						$parts = function_exists( 'wp_parse_url' ) ? wp_parse_url( $validated['value'] ) : parse_url( $validated['value'] );
						self::check_invariant(
							$result,
							is_array( $parts )
								&& isset( $parts['host'] )
								&& ( ! isset( $parts['scheme'] ) || in_array( strtolower( (string) $parts['scheme'] ), array( 'http', 'https' ), true ) )
								&& ! isset( $parts['user'], $parts['pass'] ),
							'wp_http_validate_url:accepted-url-shape',
							$url,
							array(
								'accepted' => $validated['value'],
								'parts'    => $parts,
							)
						);
					}
				}
			}
		}
	}

	private static function exercise_url_scheme_apis( array &$rng, array &$result ): void {
		$required = array( 'set_url_scheme', 'add_filter', 'remove_filter', 'has_filter', 'force_ssl_admin' );
		foreach ( $required as $function ) {
			if ( ! function_exists( $function ) ) {
				self::skip_once( $result, 'set_url_scheme', "Function {$function} is unavailable." );
				return;
			}
		}

		$filter_calls = array();
		$filter       = static function ( string $url, string $scheme, $orig_scheme ) use ( &$filter_calls ): string {
			$filter_calls[] = array(
				'url'        => $url,
				'scheme'     => $scheme,
				'origScheme' => $orig_scheme,
			);

			return $url;
		};
		$cases        = self::set_url_scheme_cases( $rng );
		$before       = \has_filter( 'set_url_scheme', $filter );

		\add_filter( 'set_url_scheme', $filter, 10, 3 );
		try {
			foreach ( $cases as $case ) {
				++$result['caseCount'];
				self::feature( $result, 'set_url_scheme' );

				$server_snapshot   = self::snapshot_server_keys( array( 'HTTPS', 'SERVER_PORT' ) );
				$previous_force    = \force_ssl_admin( (bool) $case['forceSslAdmin'] );
				$expected          = self::expected_set_url_scheme( $case['url'], $case['scheme'], (bool) $case['ssl'], (bool) $case['forceSslAdmin'] );
				$filter_call_index = count( $filter_calls );

				try {
					self::configure_ssl_server_state( (bool) $case['ssl'] );
					$actual = self::call_api(
						$result,
						'set_url_scheme',
						$case,
						static function () use ( $case ) {
							return set_url_scheme( $case['url'], $case['scheme'] );
						}
					);
				} finally {
					self::restore_server_keys( $server_snapshot );
					\force_ssl_admin( $previous_force );
				}

				if ( ! $actual['ok'] ) {
					continue;
				}

				self::check_invariant(
					$result,
					is_string( $actual['value'] ) && $expected['url'] === $actual['value'],
					'set_url_scheme:scheme-normalization',
					$case,
					array(
						'expected' => $expected,
						'actual'   => $actual['value'],
					)
				);

				self::check_invariant(
					$result,
					isset( $filter_calls[ $filter_call_index ] )
						&& $expected['url'] === $filter_calls[ $filter_call_index ]['url']
						&& $expected['scheme'] === $filter_calls[ $filter_call_index ]['scheme']
						&& $case['scheme'] === $filter_calls[ $filter_call_index ]['origScheme'],
					'set_url_scheme:filter-payload',
					$case,
					array(
						'expected'   => $expected,
						'filterCall' => $filter_calls[ $filter_call_index ] ?? null,
					)
				);

				self::check_invariant(
					$result,
					self::server_snapshot_matches( $server_snapshot ) && $previous_force === \force_ssl_admin(),
					'set_url_scheme:ssl-state-restored',
					$case,
					array(
						'serverSnapshot' => $server_snapshot,
						'serverAfter'    => self::snapshot_server_keys( array( 'HTTPS', 'SERVER_PORT' ) ),
						'forceBefore'    => $previous_force,
						'forceAfter'     => \force_ssl_admin(),
					)
				);
			}
		} finally {
			\remove_filter( 'set_url_scheme', $filter, 10 );
		}

		self::check_invariant(
			$result,
			$before === \has_filter( 'set_url_scheme', $filter ),
			'set_url_scheme:filter-restored',
			'set_url_scheme',
			array(
				'before' => $before,
				'after'  => \has_filter( 'set_url_scheme', $filter ),
			)
		);
	}

	private static function check_parse_url_components( string $url, $parts, array &$result ): void {
		$components = array(
			PHP_URL_SCHEME   => 'scheme',
			PHP_URL_HOST     => 'host',
			PHP_URL_PORT     => 'port',
			PHP_URL_USER     => 'user',
			PHP_URL_PASS     => 'pass',
			PHP_URL_PATH     => 'path',
			PHP_URL_QUERY    => 'query',
			PHP_URL_FRAGMENT => 'fragment',
		);

		foreach ( $components as $component => $key ) {
			$component_result = self::call_api(
				$result,
				'wp_parse_url.component',
				$url,
				static function () use ( $url, $component ) {
					return wp_parse_url( $url, $component );
				}
			);

			if ( ! $component_result['ok'] ) {
				continue;
			}

			$expected = false === $parts ? false : ( is_array( $parts ) && array_key_exists( $key, $parts ) ? $parts[ $key ] : null );
			self::check_invariant(
				$result,
				$component_result['value'] === $expected,
				'wp_parse_url:component-consistency',
				$url,
				array(
					'component' => $key,
					'expected'  => $expected,
					'actual'    => $component_result['value'],
					'parts'     => $parts,
				)
			);
		}
	}

	private static function exercise_path_apis( array &$rng, array &$result ): void {
		$paths = self::path_cases( $rng );

		if ( ! function_exists( 'wp_normalize_path' ) ) {
			self::skip_once( $result, 'wp_normalize_path', 'Function is unavailable.' );
		}
		if ( ! function_exists( 'validate_file' ) ) {
			self::skip_once( $result, 'validate_file', 'Function is unavailable.' );
		}

		foreach ( $paths as $path ) {
			++$result['caseCount'];
			self::feature( $result, 'path' );

			if ( function_exists( 'wp_normalize_path' ) ) {
				$normalized = self::call_api(
					$result,
					'wp_normalize_path',
					$path,
					static function () use ( $path ) {
						return wp_normalize_path( $path );
					}
				);

				if ( $normalized['ok'] ) {
					self::check_invariant(
						$result,
						is_string( $normalized['value'] ),
						'wp_normalize_path:return-string',
						$path,
						array( 'actual' => $normalized['value'] )
					);

					if ( is_string( $normalized['value'] ) ) {
						$again = self::call_api(
							$result,
							'wp_normalize_path.idempotent',
							$normalized['value'],
							static function () use ( $normalized ) {
								return wp_normalize_path( $normalized['value'] );
							}
						);

						if ( $again['ok'] ) {
							self::check_invariant(
								$result,
								$again['value'] === $normalized['value'],
								'wp_normalize_path:idempotent',
								$path,
								array(
									'first'  => $normalized['value'],
									'second' => $again['value'],
								)
							);
						}

						self::check_invariant(
							$result,
							false === strpos( $normalized['value'], '\\' ),
							'wp_normalize_path:no-backslashes',
							$path,
							array( 'normalized' => $normalized['value'] )
						);
					}
				}
			}

			if ( function_exists( 'validate_file' ) ) {
				$plain = self::call_api(
					$result,
					'validate_file',
					$path,
					static function () use ( $path ) {
						return validate_file( $path );
					}
				);

				if ( $plain['ok'] ) {
					$expected = self::expected_validate_file_code( $path, array() );
					self::check_invariant(
						$result,
						$plain['value'] === $expected,
						'validate_file:default-code',
						$path,
						array(
							'expected' => $expected,
							'actual'   => $plain['value'],
						)
					);
				}

				$allowed_files = array( 'safe/file.txt', 'folder/sub/file.php', 'safe/../' );
				$allowed       = self::call_api(
					$result,
					'validate_file.allowed',
					$path,
					static function () use ( $path, $allowed_files ) {
						return validate_file( $path, $allowed_files );
					}
				);

				if ( $allowed['ok'] ) {
					$expected = self::expected_validate_file_code( $path, $allowed_files );
					self::check_invariant(
						$result,
						$allowed['value'] === $expected,
						'validate_file:allowed-list-code',
						$path,
						array(
							'allowedFiles' => $allowed_files,
							'expected'     => $expected,
							'actual'       => $allowed['value'],
						)
					);
				}
			}
		}
	}

	private static function exercise_filename_apis( array &$rng, array &$result ): void {
		$filenames = self::filename_cases( $rng );

		if ( ! function_exists( 'sanitize_file_name' ) ) {
			self::skip_once( $result, 'sanitize_file_name', 'Function is unavailable.' );
		}
		if ( ! function_exists( 'wp_check_filetype' ) ) {
			self::skip_once( $result, 'wp_check_filetype', 'Function is unavailable.' );
		}

		foreach ( $filenames as $filename ) {
			++$result['caseCount'];
			self::feature( $result, 'filename' );

			if ( function_exists( 'sanitize_file_name' ) ) {
				$sanitized = self::call_api(
					$result,
					'sanitize_file_name',
					$filename,
					static function () use ( $filename ) {
						return sanitize_file_name( $filename );
					}
				);

				if ( $sanitized['ok'] ) {
					self::check_invariant(
						$result,
						is_string( $sanitized['value'] ),
						'sanitize_file_name:return-string',
						$filename,
						array( 'actual' => $sanitized['value'] )
					);

					if ( is_string( $sanitized['value'] ) ) {
						$again = self::call_api(
							$result,
							'sanitize_file_name.idempotent',
							$sanitized['value'],
							static function () use ( $sanitized ) {
								return sanitize_file_name( $sanitized['value'] );
							}
						);

						if ( $again['ok'] ) {
							self::check_invariant(
								$result,
								$again['value'] === $sanitized['value'],
								'sanitize_file_name:idempotent',
								$filename,
								array(
									'first'  => $sanitized['value'],
									'second' => $again['value'],
								)
							);
						}

						self::check_invariant(
							$result,
							! self::filename_has_forbidden_output_chars( $sanitized['value'] ),
							'sanitize_file_name:no-path-separator-or-nul',
							$filename,
							array( 'sanitized' => $sanitized['value'] )
						);
					}
				}
			}

			if ( function_exists( 'wp_check_filetype' ) ) {
				$filetype = self::call_api(
					$result,
					'wp_check_filetype',
					$filename,
					static function () use ( $filename ) {
						return wp_check_filetype( $filename );
					}
				);

				if ( $filetype['ok'] ) {
					self::check_filetype_shape( $filename, $filetype['value'], 'wp_check_filetype:shape', $result );

					$again = self::call_api(
						$result,
						'wp_check_filetype.repeat',
						$filename,
						static function () use ( $filename ) {
							return wp_check_filetype( $filename );
						}
					);

					if ( $again['ok'] ) {
						self::check_invariant(
							$result,
							$again['value'] === $filetype['value'],
							'wp_check_filetype:repeat-stable',
							$filename,
							array(
								'first'  => $filetype['value'],
								'second' => $again['value'],
							)
						);
					}
				}

				$lower = strtolower( $filename );
				$upper = strtoupper( $filename );
				$lower_result = self::call_api(
					$result,
					'wp_check_filetype.lower',
					$lower,
					static function () use ( $lower ) {
						return wp_check_filetype( $lower );
					}
				);
				$upper_result = self::call_api(
					$result,
					'wp_check_filetype.upper',
					$upper,
					static function () use ( $upper ) {
						return wp_check_filetype( $upper );
					}
				);

				if ( $lower_result['ok'] && $upper_result['ok'] ) {
					self::check_invariant(
						$result,
						self::filetype_case_signature( $lower_result['value'] ) === self::filetype_case_signature( $upper_result['value'] ),
						'wp_check_filetype:case-stable',
						$filename,
						array(
							'lowerInput'  => $lower,
							'upperInput'  => $upper,
							'lowerResult' => $lower_result['value'],
							'upperResult' => $upper_result['value'],
						)
					);
				}
			}
		}
	}

	private static function exercise_multisite_quota_apis( array &$rng, array &$result ): void {
		self::load_admin_multisite_helpers();

		$required = array(
			'get_space_allowed',
			'get_upload_space_available',
			'is_upload_space_available',
			'upload_size_limit_filter',
			'upload_is_user_over_quota',
			'add_filter',
			'remove_filter',
		);
		foreach ( $required as $function ) {
			if ( ! function_exists( $function ) ) {
				self::skip_once( $result, 'multisite-quota', "Function {$function} is unavailable." );
				return;
			}
		}

		foreach ( self::quota_cases( $rng ) as $case ) {
			++$result['caseCount'];
			self::feature( $result, 'multisite-quota' );

			$blog_space_filter = static function () use ( $case ) {
				return $case['blogSpace'];
			};
			$site_option_filter = static function ( $pre_site_option, string $option ) use ( $case ) {
				unset( $pre_site_option );
				if ( 'blog_upload_space' === $option ) {
					return $case['siteSpace'];
				}
				if ( 'upload_space_check_disabled' === $option ) {
					return $case['disabled'];
				}
				if ( 'fileupload_maxk' === $option ) {
					return $case['fileuploadMaxK'];
				}

				return false;
			};
			$used_filter = static function () use ( $case ) {
				return $case['usedSpace'];
			};

			\add_filter( 'pre_option_blog_upload_space', $blog_space_filter );
			\add_filter( 'pre_site_option', $site_option_filter, 10, 2 );
			\add_filter( 'pre_get_space_used', $used_filter );

			try {
				$allowed = self::call_api(
					$result,
					'get_space_allowed',
					$case,
					static function () {
						return get_space_allowed();
					}
				);
				$available = self::call_api(
					$result,
					'get_upload_space_available',
					$case,
					static function () {
						return get_upload_space_available();
					}
				);
				$has_space = self::call_api(
					$result,
					'is_upload_space_available',
					$case,
					static function () {
						return is_upload_space_available();
					}
				);
				$limit = self::call_api(
					$result,
					'upload_size_limit_filter',
					$case,
					static function () use ( $case ) {
						return upload_size_limit_filter( $case['inputLimit'] );
					}
				);
				$over_quiet = self::call_api(
					$result,
					'upload_is_user_over_quota.quiet',
					$case,
					static function () {
						ob_start();
						$over_quota = upload_is_user_over_quota( false );
						$output     = ob_get_clean();
						return array(
							'overQuota' => $over_quota,
							'output'    => $output,
						);
					}
				);
				$over_display = self::call_api(
					$result,
					'upload_is_user_over_quota.display',
					$case,
					static function () {
						ob_start();
						$over_quota = upload_is_user_over_quota( true );
						$output     = ob_get_clean();
						return array(
							'overQuota' => $over_quota,
							'output'    => $output,
						);
					}
				);
			} finally {
				\remove_filter( 'pre_get_space_used', $used_filter );
				\remove_filter( 'pre_site_option', $site_option_filter, 10 );
				\remove_filter( 'pre_option_blog_upload_space', $blog_space_filter );
			}

			$expected = self::quota_expectations( $case );
			self::check_invariant(
				$result,
				$allowed['ok'] && $expected['allowedMb'] === $allowed['value'],
				'multisite-quota:get-space-allowed-fallback',
				$case,
				array(
					'expected' => $expected['allowedMb'],
					'actual'   => $allowed['value'] ?? null,
				)
			);
			self::check_invariant(
				$result,
				$available['ok'] && $expected['availableBytes'] === $available['value'],
				'multisite-quota:get-upload-space-available-bytes',
				$case,
				array(
					'expected' => $expected['availableBytes'],
					'actual'   => $available['value'] ?? null,
				)
			);
			self::check_invariant(
				$result,
				$has_space['ok'] && $expected['hasSpace'] === $has_space['value'],
				'multisite-quota:is-upload-space-available',
				$case,
				array(
					'expected' => $expected['hasSpace'],
					'actual'   => $has_space['value'] ?? null,
				)
			);
			self::check_invariant(
				$result,
				$limit['ok'] && $expected['uploadLimit'] === $limit['value'],
				'multisite-quota:upload-size-limit-filter-minimum',
				$case,
				array(
					'expected' => $expected['uploadLimit'],
					'actual'   => $limit['value'] ?? null,
				)
			);
			self::check_invariant(
				$result,
				$over_quiet['ok']
					&& is_array( $over_quiet['value'] )
					&& $expected['overQuota'] === $over_quiet['value']['overQuota']
					&& '' === $over_quiet['value']['output'],
				'multisite-quota:quiet-over-quota-boolean-no-output',
				$case,
				array(
					'expected' => $expected['overQuota'],
					'actual'   => $over_quiet['value'] ?? null,
				)
			);
			self::check_invariant(
				$result,
				$over_display['ok']
					&& is_array( $over_display['value'] )
					&& $expected['overQuota'] === $over_display['value']['overQuota']
					&& ( $expected['overQuota'] ? '' !== $over_display['value']['output'] : '' === $over_display['value']['output'] ),
				'multisite-quota:display-over-quota-output-is-bounded',
				$case,
				array(
					'expected' => $expected['overQuota'],
					'actual'   => $over_display['value'] ?? null,
				)
			);
		}
	}

	private static function exercise_multisite_upload_policy_apis( array &$rng, array &$result ): void {
		self::load_admin_multisite_helpers();

		$required = array(
			'check_upload_mimes',
			'upload_is_file_too_big',
			'add_filter',
			'remove_filter',
			'has_filter',
		);
		foreach ( $required as $function ) {
			if ( ! function_exists( $function ) ) {
				self::skip_once( $result, 'multisite-upload-policy', "Function {$function} is unavailable." );
				return;
			}
		}

		$mimes = array(
			'jpg|jpeg|jpe'       => 'image/jpeg',
			'png'                => 'image/png',
			'gif'                => 'image/gif',
			'webp'               => 'image/webp',
			'pdf'                => 'application/pdf',
			'txt|asc|c|cc|h'     => 'text/plain',
			'tar|gz'             => 'application/gzip',
			'component-fuzz-bin' => 'application/x-component-fuzz',
		);

		foreach ( self::upload_policy_cases( $rng ) as $case ) {
			++$result['caseCount'];
			self::feature( $result, 'multisite-upload-policy' );

			$site_option_filter = static function ( $pre_site_option, string $option ) use ( $case ) {
				unset( $pre_site_option );
				if ( 'upload_filetypes' === $option ) {
					return $case['uploadFiletypes'];
				}
				if ( 'upload_space_check_disabled' === $option ) {
					return $case['disabled'];
				}
				if ( 'fileupload_maxk' === $option ) {
					return $case['fileuploadMaxK'];
				}

				return false;
			};
			$before_filter      = \has_filter( 'pre_site_option', $site_option_filter );

			\add_filter( 'pre_site_option', $site_option_filter, 10, 2 );
			try {
				$filtered_mimes = self::call_api(
					$result,
					'check_upload_mimes',
					$case,
					static function () use ( $mimes ) {
						return check_upload_mimes( $mimes );
					}
				);

				$upload = array(
					'name' => $case['name'],
					'type' => 'application/octet-stream',
					'bits' => str_repeat( 'x', $case['bytes'] ),
				);

				$size_check = self::call_api(
					$result,
					'upload_is_file_too_big',
					$case,
					static function () use ( $upload ) {
						return upload_is_file_too_big( $upload );
					}
				);

				$non_array_check = self::call_api(
					$result,
					'upload_is_file_too_big.non-array',
					$case['name'],
					static function () use ( $case ) {
						return upload_is_file_too_big( $case['name'] );
					}
				);
			} finally {
				\remove_filter( 'pre_site_option', $site_option_filter, 10 );
			}

			$expected_mimes = self::expected_upload_mimes( $mimes, $case['uploadFiletypes'] );
			self::check_invariant(
				$result,
				$filtered_mimes['ok'] && $expected_mimes === $filtered_mimes['value'],
				'multisite-upload-policy:check-upload-mimes-filters-by-network-filetypes',
				$case,
				array(
					'expected' => $expected_mimes,
					'actual'   => $filtered_mimes['value'] ?? null,
				)
			);

			$max_bytes    = (int) $case['fileuploadMaxK'] * KB_IN_BYTES;
			$should_block = ! (bool) $case['disabled'] && $case['bytes'] > $max_bytes;
			self::check_invariant(
				$result,
				$size_check['ok']
					&& (
						$should_block
							? ( is_string( $size_check['value'] ) && str_contains( $size_check['value'], (string) $case['fileuploadMaxK'] ) && str_ends_with( $size_check['value'], '<br />' ) )
							: $upload === $size_check['value']
					),
				'multisite-upload-policy:upload-is-file-too-big-thresholds',
				$case,
				array(
					'expectedBlocked' => $should_block,
					'maxBytes'        => $max_bytes,
					'actual'          => self::describe_value( $size_check['value'] ?? null ),
				)
			);

			self::check_invariant(
				$result,
				$non_array_check['ok']
					&& $case['name'] === $non_array_check['value']
					&& $before_filter === \has_filter( 'pre_site_option', $site_option_filter ),
				'multisite-upload-policy:state-and-non-array-bypass',
				$case,
				array(
					'nonArray'     => $non_array_check['value'] ?? null,
					'filterBefore' => $before_filter,
					'filterAfter'  => \has_filter( 'pre_site_option', $site_option_filter ),
				)
			);
		}
	}

	private static function exercise_multisite_check_upload_size_apis( array &$rng, array &$result, string $temp_root ): void {
		self::load_admin_multisite_helpers();

		$required = array(
			'check_upload_size',
			'add_filter',
			'remove_filter',
			'has_filter',
		);
		foreach ( $required as $function ) {
			if ( ! function_exists( $function ) ) {
				self::skip_once( $result, 'check_upload_size', "Function {$function} is unavailable." );
				return;
			}
		}

		$upload_size_dir = $temp_root . DIRECTORY_SEPARATOR . 'check-upload-size';
		self::ensure_dir( $upload_size_dir );

		foreach ( self::check_upload_size_cases( $rng ) as $index => $case ) {
			++$result['caseCount'];
			self::feature( $result, 'check_upload_size' );

			$tmp_name = $upload_size_dir . DIRECTORY_SEPARATOR . 'case-' . $index . '.bin';
			file_put_contents( $tmp_name, str_repeat( 'x', $case['fileSize'] ) );

			$file = array(
				'name'     => $case['name'],
				'type'     => 'application/octet-stream',
				'tmp_name' => $tmp_name,
				'error'    => $case['inputError'],
				'size'     => $case['fileSize'],
			);

			$site_option_filter = static function ( $pre_site_option, string $option ) use ( $case ) {
				unset( $pre_site_option );
				if ( 'blog_upload_space' === $option ) {
					return $case['allowedMb'];
				}
				if ( 'upload_space_check_disabled' === $option ) {
					return $case['disabled'];
				}
				if ( 'fileupload_maxk' === $option ) {
					return $case['fileuploadMaxK'];
				}

				return false;
			};
			$blog_space_filter  = static function () use ( $case ) {
				return $case['allowedMb'];
			};
			$used_filter        = static function () use ( $case ) {
				return $case['usedMb'];
			};
			$before_site_filter = \has_filter( 'pre_site_option', $site_option_filter );
			$before_blog_filter = \has_filter( 'pre_option_blog_upload_space', $blog_space_filter );
			$before_used_filter = \has_filter( 'pre_get_space_used', $used_filter );
			$post_snapshot      = $_POST;
			$buffer_level       = ob_get_level();

			\add_filter( 'pre_site_option', $site_option_filter, 10, 2 );
			\add_filter( 'pre_option_blog_upload_space', $blog_space_filter );
			\add_filter( 'pre_get_space_used', $used_filter );

			try {
				$_POST['html-upload'] = '1';
				$checked              = self::call_api(
					$result,
					'check_upload_size',
					$case,
					static function () use ( $file ) {
						ob_start();
						$checked = check_upload_size( $file );
						$output  = ob_get_clean();
						return array(
							'file'   => $checked,
							'output' => $output,
						);
					}
				);
			} finally {
				while ( ob_get_level() > $buffer_level ) {
					ob_end_clean();
				}
				$_POST = $post_snapshot;
				\remove_filter( 'pre_get_space_used', $used_filter );
				\remove_filter( 'pre_option_blog_upload_space', $blog_space_filter );
				\remove_filter( 'pre_site_option', $site_option_filter, 10 );
			}

			if ( ! $checked['ok'] || ! is_array( $checked['value'] ) || ! is_array( $checked['value']['file'] ?? null ) ) {
				self::check_invariant(
					$result,
					false,
					'check_upload_size:return-shape',
					$case,
					array( 'actual' => $checked )
				);
				continue;
			}

			$checked_file = $checked['value']['file'];
			$expected_ok  = 'ok' === $case['expected'];

			self::check_invariant(
				$result,
				$file['name'] === ( $checked_file['name'] ?? null )
					&& $file['tmp_name'] === ( $checked_file['tmp_name'] ?? null )
					&& $file['size'] === ( $checked_file['size'] ?? null ),
				'check_upload_size:file-identity-preserved',
				$case,
				array(
					'input'  => $file,
					'actual' => $checked_file,
				)
			);
			self::check_invariant(
				$result,
				$expected_ok
					? $case['inputError'] === ( $checked_file['error'] ?? null )
					: ( isset( $checked_file['error'] ) && '' !== (string) $checked_file['error'] && 0 !== $checked_file['error'] ),
				'check_upload_size:error-contract',
				$case,
				array(
					'expected' => $case['expected'],
					'input'    => $file,
					'actual'   => $checked_file,
				)
			);
			self::check_invariant(
				$result,
				'' === ( $checked['value']['output'] ?? null )
					&& $post_snapshot === $_POST
					&& $before_site_filter === \has_filter( 'pre_site_option', $site_option_filter )
					&& $before_blog_filter === \has_filter( 'pre_option_blog_upload_space', $blog_space_filter )
					&& $before_used_filter === \has_filter( 'pre_get_space_used', $used_filter ),
				'check_upload_size:state-output-filters-restored',
				$case,
				array(
					'output'            => $checked['value']['output'] ?? null,
					'postBefore'        => $post_snapshot,
					'postAfter'         => $_POST,
					'siteFilterBefore'  => $before_site_filter,
					'siteFilterAfter'   => \has_filter( 'pre_site_option', $site_option_filter ),
					'blogFilterBefore'  => $before_blog_filter,
					'blogFilterAfter'   => \has_filter( 'pre_option_blog_upload_space', $blog_space_filter ),
					'usedFilterBefore'  => $before_used_filter,
					'usedFilterAfter'   => \has_filter( 'pre_get_space_used', $used_filter ),
				)
			);
		}
	}

	private static function exercise_filetype_and_unique_apis( array &$rng, array &$result, string $temp_root ): void {
		$filetype_dir = $temp_root . DIRECTORY_SEPARATOR . 'filetype';
		$unique_dir   = $temp_root . DIRECTORY_SEPARATOR . 'unique';
		self::ensure_dir( $filetype_dir );
		self::ensure_dir( $unique_dir );

		if ( ! function_exists( 'wp_check_filetype_and_ext' ) ) {
			self::skip_once( $result, 'wp_check_filetype_and_ext', 'Function is unavailable.' );
		} else {
			$local_files = array(
				array( 'name' => 'notes.txt', 'bytes' => "plain text\n" ),
				array( 'name' => 'fake-image.jpg', 'bytes' => "plain text pretending to be a jpeg\n" ),
				array( 'name' => 'tiny.gif', 'bytes' => "GIF89a\x01\x00\x01\x00\x80\x00\x00\x00\x00\x00\xff\xff\xff,\x00\x00\x00\x00\x01\x00\x01\x00\x00\x02\x02D\x01\x00;" ),
				array( 'name' => 'document.pdf', 'bytes' => "%PDF-1.3\n1 0 obj\n<<>>\nendobj\n" ),
				array( 'name' => 'vector.svg', 'bytes' => "<svg xmlns=\"http://www.w3.org/2000/svg\"></svg>\n" ),
			);

			foreach ( $local_files as $index => $local ) {
				++$result['caseCount'];
				self::feature( $result, 'filetype-and-ext:local' );

				$path = $filetype_dir . DIRECTORY_SEPARATOR . 'case-' . $index . '.bin';
				file_put_contents( $path, $local['bytes'] );

				$checked = self::call_api(
					$result,
					'wp_check_filetype_and_ext',
					$local['name'],
					static function () use ( $path, $local ) {
						return wp_check_filetype_and_ext( $path, $local['name'] );
					}
				);

				if ( $checked['ok'] ) {
					self::check_filetype_and_ext_shape( $local['name'], $checked['value'], 'wp_check_filetype_and_ext:shape', $result );

					$again = self::call_api(
						$result,
						'wp_check_filetype_and_ext.repeat',
						$local['name'],
						static function () use ( $path, $local ) {
							return wp_check_filetype_and_ext( $path, $local['name'] );
						}
					);

					if ( $again['ok'] ) {
						self::check_invariant(
							$result,
							$again['value'] === $checked['value'],
							'wp_check_filetype_and_ext:repeat-stable',
							$local['name'],
							array(
								'first'  => $checked['value'],
								'second' => $again['value'],
							)
						);
					}
				}

				$missing_path = $filetype_dir . DIRECTORY_SEPARATOR . 'missing-' . $index . '.bin';
				$lower_name   = strtolower( $local['name'] );
				$upper_name   = strtoupper( $local['name'] );
				$lower        = self::call_api(
					$result,
					'wp_check_filetype_and_ext.lower-missing',
					$lower_name,
					static function () use ( $missing_path, $lower_name ) {
						return wp_check_filetype_and_ext( $missing_path, $lower_name );
					}
				);
				$upper        = self::call_api(
					$result,
					'wp_check_filetype_and_ext.upper-missing',
					$upper_name,
					static function () use ( $missing_path, $upper_name ) {
						return wp_check_filetype_and_ext( $missing_path, $upper_name );
					}
				);

				if ( $lower['ok'] && $upper['ok'] ) {
					self::check_invariant(
						$result,
						self::filetype_case_signature( $lower['value'] ) === self::filetype_case_signature( $upper['value'] ),
						'wp_check_filetype_and_ext:case-stable-without-file-read',
						$local['name'],
						array(
							'lowerResult' => $lower['value'],
							'upperResult' => $upper['value'],
						)
					);
				}
			}

			foreach ( self::custom_mime_filetype_cases( $rng ) as $index => $case ) {
				++$result['caseCount'];
				self::feature( $result, 'filetype-and-ext:custom-mimes' );

				$missing_path = $filetype_dir . DIRECTORY_SEPARATOR . 'custom-missing-' . $index . '.bin';
				$checked      = self::call_api(
					$result,
					'wp_check_filetype_and_ext.custom-mimes',
					$case['filename'],
					static function () use ( $missing_path, $case ) {
						return wp_check_filetype_and_ext( $missing_path, $case['filename'], $case['mimes'] );
					}
				);

				if ( ! $checked['ok'] ) {
					continue;
				}

				self::check_filetype_and_ext_shape( $case['filename'], $checked['value'], 'wp_check_filetype_and_ext:custom-mimes-shape', $result );
				self::check_invariant(
					$result,
					array(
						'ext'             => $case['expectedExt'],
						'type'            => $case['expectedType'],
						'proper_filename' => false,
					) === $checked['value'],
					'wp_check_filetype_and_ext:custom-mimes-allowlist-oracle',
					$case['filename'],
					array(
						'mimes'    => $case['mimes'],
						'expected' => array(
							'ext'             => $case['expectedExt'],
							'type'            => $case['expectedType'],
							'proper_filename' => false,
						),
						'actual'   => $checked['value'],
					)
				);
			}
		}

		if ( ! function_exists( 'wp_unique_filename' ) ) {
			self::skip_once( $result, 'wp_unique_filename', 'Function is unavailable.' );
			return;
		}

		$existing = array(
			'photo.jpg'             => 'x',
			'photo-1.jpg'           => 'x',
			'avatar-150x150.png'    => 'x',
			'report.pdf'            => 'x',
			'image.JPG'             => 'x',
			'thumb-rotated.webp'    => 'x',
			'thumb-rotated-1.webp'  => 'x',
		);

		foreach ( $existing as $name => $bytes ) {
			file_put_contents( $unique_dir . DIRECTORY_SEPARATOR . $name, $bytes );
		}

		$candidates = array_merge(
			array(
				'photo.JPG',
				'photo.jpg',
				'avatar-150x150.PNG',
				'report.pdf',
				'new file.txt',
				'a/b.php.jpg',
				'thumb-rotated.WEBP',
				'archive.tar.gz',
			),
			self::generated_unique_filename_cases( $rng )
		);

		foreach ( $candidates as $candidate ) {
			++$result['caseCount'];
			self::feature( $result, 'wp_unique_filename' );

			$unique = self::call_api(
				$result,
				'wp_unique_filename',
				$candidate,
				static function () use ( $unique_dir, $candidate ) {
					return wp_unique_filename( $unique_dir, $candidate );
				}
			);

			if ( ! $unique['ok'] ) {
				continue;
			}

			self::check_invariant(
				$result,
				is_string( $unique['value'] ),
				'wp_unique_filename:return-string',
				$candidate,
				array( 'actual' => $unique['value'] )
			);

			if ( ! is_string( $unique['value'] ) ) {
				continue;
			}

			self::check_invariant(
				$result,
				'' !== $unique['value'] && ! self::filename_has_forbidden_output_chars( $unique['value'] ),
				'wp_unique_filename:safe-basename',
				$candidate,
				array( 'unique' => $unique['value'] )
			);

			self::check_invariant(
				$result,
				! file_exists( $unique_dir . DIRECTORY_SEPARATOR . $unique['value'] ),
				'wp_unique_filename:non-conflicting',
				$candidate,
				array( 'unique' => $unique['value'] )
			);

			if ( function_exists( 'sanitize_file_name' ) ) {
				self::check_invariant(
					$result,
					sanitize_file_name( $unique['value'] ) === $unique['value'],
					'wp_unique_filename:sanitized-output',
					$candidate,
					array( 'unique' => $unique['value'] )
				);
			}

			$again = self::call_api(
				$result,
				'wp_unique_filename.repeat',
				$candidate,
				static function () use ( $unique_dir, $candidate ) {
					return wp_unique_filename( $unique_dir, $candidate );
				}
			);

			if ( $again['ok'] ) {
				self::check_invariant(
					$result,
					$again['value'] === $unique['value'],
					'wp_unique_filename:repeat-stable',
					$candidate,
					array(
						'first'  => $unique['value'],
						'second' => $again['value'],
					)
				);
			}
		}

		self::exercise_unique_filename_callback_filter( $rng, $result, $unique_dir );
	}

	private static function exercise_unique_filename_callback_filter( array &$rng, array &$result, string $unique_dir ): void {
		if ( ! function_exists( 'wp_unique_filename' ) ) {
			self::skip_once( $result, 'wp_unique_filename_callback', 'Function wp_unique_filename is unavailable.' );
			return;
		}
		if ( ! function_exists( 'add_filter' ) || ! function_exists( 'remove_filter' ) || ! function_exists( 'has_filter' ) ) {
			self::skip_once( $result, 'wp_unique_filename_callback', 'Filter API is unavailable.' );
			return;
		}

		++$result['caseCount'];
		self::feature( $result, 'wp_unique_filename:callback-filter' );

		$token              = 'cfz-' . self::rng_int( $rng, 1000, 9999 );
		$input              = "Callback {$token} Image.JPG";
		$sanitized          = function_exists( 'sanitize_file_name' ) ? sanitize_file_name( $input ) : $input;
		$expected_name      = pathinfo( $sanitized, PATHINFO_BASENAME );
		$expected_ext       = '.' . pathinfo( $sanitized, PATHINFO_EXTENSION );
		$callback_filename  = "callback-{$token}{$expected_ext}";
		$expected_filename  = "filtered-{$callback_filename}";
		$callback_calls     = array();
		$filter_calls       = array();
		$unique_callback    = static function ( string $dir, string $name, string $ext ) use ( &$callback_calls, $callback_filename ): string {
			$callback_calls[] = array(
				'dir'  => $dir,
				'name' => $name,
				'ext'  => $ext,
			);

			return $callback_filename;
		};
		$unique_name_filter = static function (
			string $filename,
			string $ext,
			string $dir,
			$callback,
			array $alt_filenames,
			$number
		) use ( &$filter_calls, $unique_callback ): string {
			$filter_calls[] = array(
				'filename'   => $filename,
				'ext'        => $ext,
				'dir'        => $dir,
				'callback'   => $callback,
				'altNames'   => $alt_filenames,
				'number'     => $number,
				'sameObject' => $callback === $unique_callback,
			);

			return 'filtered-' . $filename;
		};
		$before_filter      = \has_filter( 'wp_unique_filename', $unique_name_filter );

		\add_filter( 'wp_unique_filename', $unique_name_filter, 10, 6 );
		try {
			$unique = self::call_api(
				$result,
				'wp_unique_filename.callback-filter',
				$input,
				static function () use ( $unique_dir, $input, $unique_callback ) {
					return wp_unique_filename( $unique_dir, $input, $unique_callback );
				}
			);
		} finally {
			\remove_filter( 'wp_unique_filename', $unique_name_filter, 10 );
		}

		self::check_invariant(
			$result,
			$unique['ok'] && $expected_filename === $unique['value'],
			'wp_unique_filename:callback-filter-return',
			$input,
			array(
				'expected' => $expected_filename,
				'actual'   => $unique['value'] ?? null,
			)
		);

		self::check_invariant(
			$result,
			array(
				array(
					'dir'  => $unique_dir,
					'name' => $expected_name,
					'ext'  => $expected_ext,
				),
			) === $callback_calls,
			'wp_unique_filename:callback-receives-sanitized-parts',
			$input,
			array(
				'expectedName' => $expected_name,
				'expectedExt'  => $expected_ext,
				'calls'        => $callback_calls,
			)
		);

		self::check_invariant(
			$result,
			1 === count( $filter_calls )
				&& $callback_filename === $filter_calls[0]['filename']
				&& $expected_ext === $filter_calls[0]['ext']
				&& $unique_dir === $filter_calls[0]['dir']
				&& true === $filter_calls[0]['sameObject']
				&& array() === $filter_calls[0]['altNames']
				&& '' === $filter_calls[0]['number']
				&& $before_filter === \has_filter( 'wp_unique_filename', $unique_name_filter ),
			'wp_unique_filename:filter-payload-and-restoration',
			$input,
			array(
				'filterCalls'  => self::describe_value( $filter_calls ),
				'filterBefore' => $before_filter,
				'filterAfter'  => \has_filter( 'wp_unique_filename', $unique_name_filter ),
			)
		);
	}

	private static function exercise_sideload_helpers( array &$rng, array &$result, string $temp_root ): void {
		unset( $rng );
		self::load_admin_file_helpers();

		if ( ! function_exists( 'wp_handle_sideload' ) ) {
			self::skip_once( $result, 'wp_handle_sideload', 'Function is unavailable.' );
			return;
		}
		if ( ! function_exists( 'add_filter' ) || ! function_exists( 'remove_filter' ) ) {
			self::skip_once( $result, 'wp_handle_sideload', 'Filter API is unavailable.' );
			return;
		}

		$upload_root = $temp_root . DIRECTORY_SEPARATOR . 'sideload-uploads';
		$tmp_root    = $temp_root . DIRECTORY_SEPARATOR . 'sideload-tmp';
		self::ensure_dir( $upload_root );
		self::ensure_dir( $tmp_root );

		$cases = array(
			array( 'name' => 'side load.txt', 'type' => 'text/plain', 'bytes' => "plain text\n" ),
			array( 'name' => 'nested/path.txt', 'type' => 'text/plain', 'bytes' => "path separators in client filename\n" ),
		);

		self::$sideload_upload_root = $upload_root;
		add_filter( 'upload_dir', array( __CLASS__, 'filter_sideload_upload_dir' ) );

		try {
			foreach ( $cases as $index => $case ) {
				++$result['caseCount'];
				self::feature( $result, 'wp_handle_sideload' );

				$tmp_name = $tmp_root . DIRECTORY_SEPARATOR . 'sideload-' . $index . '.tmp';
				file_put_contents( $tmp_name, $case['bytes'] );
				$file = array(
					'name'     => $case['name'],
					'type'     => $case['type'],
					'tmp_name' => $tmp_name,
					'error'    => 0,
					'size'     => strlen( $case['bytes'] ),
				);

				$handled = self::call_api(
					$result,
					'wp_handle_sideload',
					$case['name'],
					static function () use ( &$file ) {
						return wp_handle_sideload(
							$file,
							array(
								'test_form' => false,
								'mimes'     => array(
									'txt|asc|c|cc|h|srt' => 'text/plain',
									'csv'                => 'text/csv',
								),
							),
							'2026/06'
						);
					}
				);

				if ( ! $handled['ok'] ) {
					continue;
				}

				$value = $handled['value'];
				self::check_invariant(
					$result,
					is_array( $value ) && ! isset( $value['error'] ),
					'wp_handle_sideload:valid-local-file',
					$case['name'],
					array( 'result' => $value )
				);

				if ( is_array( $value ) && ! isset( $value['error'] ) ) {
					$file_path = isset( $value['file'] ) ? (string) $value['file'] : '';
					$url       = isset( $value['url'] ) ? (string) $value['url'] : '';
					$type      = isset( $value['type'] ) ? (string) $value['type'] : '';

					self::check_invariant(
						$result,
						0 === strpos( self::normalize_directory_separators( $file_path ), self::normalize_directory_separators( $upload_root ) . '/' )
							&& is_file( $file_path )
							&& false === strpos( basename( $file_path ), '/' )
							&& false === strpos( basename( $file_path ), '\\' ),
						'wp_handle_sideload:temp-upload-path',
						$case['name'],
						array(
							'file'       => $file_path,
							'uploadRoot' => $upload_root,
						)
					);

					self::check_invariant(
						$result,
						0 === strpos( $url, 'http://example.test/component-fuzz-network' ) && '' !== $type,
						'wp_handle_sideload:url-and-type',
						$case['name'],
						array(
							'url'  => $url,
							'type' => $type,
						)
					);
				}
			}
		} finally {
			remove_filter( 'upload_dir', array( __CLASS__, 'filter_sideload_upload_dir' ) );
			self::$sideload_upload_root = null;
		}
	}

	private static function check_filetype_shape( string $input, $value, string $invariant, array &$result ): void {
		self::check_invariant(
			$result,
			is_array( $value )
				&& array_key_exists( 'ext', $value )
				&& array_key_exists( 'type', $value )
				&& ( false === $value['ext'] || is_string( $value['ext'] ) )
				&& ( false === $value['type'] || is_string( $value['type'] ) ),
			$invariant,
			$input,
			array( 'actual' => $value )
		);
	}

	private static function check_filetype_and_ext_shape( string $input, $value, string $invariant, array &$result ): void {
		self::check_invariant(
			$result,
			is_array( $value )
				&& array_key_exists( 'ext', $value )
				&& array_key_exists( 'type', $value )
				&& array_key_exists( 'proper_filename', $value )
				&& ( false === $value['ext'] || is_string( $value['ext'] ) )
				&& ( false === $value['type'] || is_string( $value['type'] ) )
				&& ( false === $value['proper_filename'] || is_string( $value['proper_filename'] ) ),
			$invariant,
			$input,
			array( 'actual' => $value )
		);
	}

	private static function expected_validate_file_code( $file, array $allowed_files ): int {
		if ( ! is_scalar( $file ) || '' === $file ) {
			return 0;
		}

		$file = function_exists( 'wp_normalize_path' ) ? wp_normalize_path( $file ) : self::normalize_directory_separators( (string) $file );
		foreach ( $allowed_files as $index => $allowed ) {
			$allowed_files[ $index ] = function_exists( 'wp_normalize_path' ) ? wp_normalize_path( $allowed ) : self::normalize_directory_separators( (string) $allowed );
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

		if ( isset( $file[1] ) && ':' === $file[1] ) {
			return 2;
		}

		return 0;
	}

	private static function is_http_validate_probe_safe( string $url ): bool {
		if ( '' === $url || is_numeric( $url ) ) {
			return true;
		}

		$scheme = parse_url( $url, PHP_URL_SCHEME );
		if ( is_string( $scheme ) && ! in_array( strtolower( $scheme ), array( 'http', 'https' ), true ) ) {
			return true;
		}

		$parts = @parse_url( $url );
		if ( false === $parts || ! is_array( $parts ) || empty( $parts['host'] ) ) {
			return true;
		}

		if ( isset( $parts['user'] ) || isset( $parts['pass'] ) ) {
			return true;
		}

		$host = (string) $parts['host'];
		if ( false !== strpbrk( $host, ':#?[]' ) ) {
			return true;
		}

		$trimmed_host = trim( $host, '.' );
		if ( self::is_ipv4_literal( $trimmed_host ) ) {
			return true;
		}

		$home_host = self::home_host();
		return null !== $home_host && strtolower( $home_host ) === strtolower( $host );
	}

	private static function home_host(): ?string {
		if ( function_exists( 'get_option' ) ) {
			$home = get_option( 'home' );
			if ( is_string( $home ) ) {
				$host = parse_url( $home, PHP_URL_HOST );
				if ( is_string( $host ) && '' !== $host ) {
					return $host;
				}
			}
		}

		return null;
	}

	private static function is_ipv4_literal( string $host ): bool {
		if ( 1 !== preg_match( '/^(([0-9]{1,3})\.){3}([0-9]{1,3})$/', $host ) ) {
			return false;
		}

		foreach ( explode( '.', $host ) as $part ) {
			if ( (int) $part > 255 ) {
				return false;
			}
		}

		return true;
	}

	private static function is_reconstructable_url_parts( array $parts ): bool {
		if ( empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
			return false;
		}

		if ( ! is_string( $parts['scheme'] ) || 1 !== preg_match( '/^[A-Za-z][A-Za-z0-9+.-]*$/', $parts['scheme'] ) ) {
			return false;
		}

		if ( ! is_string( $parts['host'] ) || '' === $parts['host'] || 1 === preg_match( '/[\x00-\x20\/?#@]/', $parts['host'] ) ) {
			return false;
		}

		foreach ( array( 'user', 'pass', 'path', 'query', 'fragment' ) as $key ) {
			if ( isset( $parts[ $key ] ) && ( ! is_string( $parts[ $key ] ) || false !== strpos( $parts[ $key ], chr( 0 ) ) ) ) {
				return false;
			}
		}

		if ( isset( $parts['path'] ) && '' !== $parts['path'] && '/' !== $parts['path'][0] ) {
			return false;
		}

		return ! isset( $parts['port'] ) || is_int( $parts['port'] );
	}

	private static function rebuild_url( array $parts ): string {
		$url = $parts['scheme'] . '://';

		if ( isset( $parts['user'] ) ) {
			$url .= $parts['user'];
			if ( isset( $parts['pass'] ) ) {
				$url .= ':' . $parts['pass'];
			}
			$url .= '@';
		}

		$url .= $parts['host'];

		if ( isset( $parts['port'] ) ) {
			$url .= ':' . $parts['port'];
		}

		$url .= isset( $parts['path'] ) ? $parts['path'] : '';

		if ( array_key_exists( 'query', $parts ) ) {
			$url .= '?' . $parts['query'];
		}

		if ( array_key_exists( 'fragment', $parts ) ) {
			$url .= '#' . $parts['fragment'];
		}

		return $url;
	}

	private static function same_url_parts( array $expected, array $actual ): bool {
		foreach ( array( 'scheme', 'host', 'port', 'user', 'pass', 'path', 'query', 'fragment' ) as $key ) {
			$expected_has = array_key_exists( $key, $expected );
			$actual_has   = array_key_exists( $key, $actual );
			if ( $expected_has !== $actual_has ) {
				return false;
			}
			if ( $expected_has && $expected[ $key ] !== $actual[ $key ] ) {
				return false;
			}
		}

		return true;
	}

	private static function filename_has_forbidden_output_chars( string $filename ): bool {
		return false !== strpos( $filename, '/' )
			|| false !== strpos( $filename, '\\' )
			|| false !== strpos( $filename, chr( 0 ) )
			|| false !== strpbrk( $filename, "\r\n\t" );
	}

	private static function filetype_case_signature( $value ): array {
		if ( ! is_array( $value ) ) {
			return array( 'invalid' => gettype( $value ) );
		}

		$ext = isset( $value['ext'] ) && is_string( $value['ext'] ) ? strtolower( $value['ext'] ) : $value['ext'] ?? null;

		return array(
			'ext'  => $ext,
			'type' => $value['type'] ?? null,
		);
	}

	private static function call_api( array &$result, string $api, $input, callable $callback ): array {
		++$result['apiCalls'];

		try {
			return array(
				'ok'    => true,
				'value' => $callback(),
			);
		} catch ( \Throwable $e ) {
			self::record_failure(
				$result,
				$api . ':no-throw',
				$input,
				array(
					'throwable' => get_class( $e ),
					'message'   => $e->getMessage(),
				)
			);

			return array(
				'ok'    => false,
				'value' => null,
			);
		}
	}

	private static function check_invariant( array &$result, bool $condition, string $invariant, $input, array $details = array() ): void {
		++$result['assertions'];
		if ( $condition ) {
			return;
		}

		self::record_failure( $result, $invariant, $input, $details );
	}

	private static function record_failure( array &$result, string $invariant, $input, array $details = array() ): void {
		++$result['failureCount'];

		if ( count( $result['failures'] ) >= self::MAX_FAILURES ) {
			return;
		}

		$result['failures'][] = array(
			'invariant' => $invariant,
			'input'     => self::describe_value( $input ),
			'details'   => self::describe_value( $details ),
		);
	}

	private static function feature( array &$result, string $feature ): void {
		$result['features'][ $feature ] = true;
	}

	private static function skip_once( array &$result, string $name, string $reason ): void {
		if ( ! isset( $result['skipped'][ $name ] ) ) {
			$result['skipped'][ $name ] = $reason;
		}
	}

	private static function seed_from_context( \ComponentFuzz\FuzzContext $ctx ): int {
		foreach ( array( 'seed', 'getSeed', 'currentSeed', 'iteration', 'getIteration' ) as $method ) {
			if ( is_callable( array( $ctx, $method ) ) ) {
				try {
					$seed = self::coerce_seed( $ctx->$method() );
					if ( null !== $seed ) {
						return $seed;
					}
				} catch ( \Throwable $e ) {
					continue;
				}
			}
		}

		foreach ( get_object_vars( $ctx ) as $key => $value ) {
			if ( in_array( $key, array( 'seed', 'currentSeed', 'iteration' ), true ) ) {
				$seed = self::coerce_seed( $value );
				if ( null !== $seed ) {
					return $seed;
				}
			}
		}

		return 1;
	}

	private static function coerce_seed( $value ): ?int {
		if ( is_int( $value ) ) {
			return max( 1, abs( $value ) );
		}

		if ( is_float( $value ) ) {
			return max( 1, abs( (int) $value ) );
		}

		if ( is_string( $value ) && is_numeric( $value ) ) {
			return max( 1, abs( (int) $value ) );
		}

		if ( is_string( $value ) && '' !== $value ) {
			return max( 1, (int) hexdec( substr( sha1( $value ), 0, 7 ) ) );
		}

		return null;
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

	private static function url_cases( array &$rng ): array {
		$cases = array(
			'',
			'http://example.com/path?query#frag',
			'https://user:pass@example.com:443/a/b?x=1&y=two#frag',
			'//example.com/path?x:y',
			'/relative/path?x:y',
			'http://127.0.0.1/',
			'http://192.168.1.1:8080/',
			'http://0.0.0.0/',
			'http://8.8.8.8:81/',
			'http://[::1]/',
			'http://[2001:db8::1]:8080/a',
			'http://example.com:99999/',
			'http://-bad-.test/',
			'http://ex ample.com/',
			"http://example.com/\0path",
			"https://example.com/%0d%0aHeader:%20x",
			"http://xn--exmple-cua.test/\xc3\xb1?utf=%e2%98%83",
			"http://t\xc3\xa4st.de/path",
			'mailto:user@example.com',
			'ftp://example.com/file',
			'javascript:alert(1)',
			'http:///missing-host',
			'http://user@example.com@',
			'https://example.com:443/path;params?x[]=1',
			'http://example.com/?a=http://b',
			'http://example.com#frag:with:colon',
			'http://[::ffff:127.0.0.1]/',
			'https://example.com/%E0%A4%A',
			'HTTPS://EXAMPLE.COM/A?B=C',
		);

		for ( $i = 0; $i < 32; ++$i ) {
			$cases[] = self::generated_url( $rng );
		}

		return array_values( array_unique( $cases ) );
	}

	private static function generated_url( array &$rng ): string {
		$schemes = array( 'http', 'https', 'HTTP', 'ftp', 'file', 'data', 'javascript', '' );
		$hosts   = array(
			'example.com',
			'EXAMPLE.com.',
			'127.0.0.1',
			'192.168.0.1',
			'10.0.0.1',
			'172.16.0.1',
			'169.254.169.254',
			'8.8.8.8',
			'[::1]',
			'[2001:db8::1]',
			'xn--bcher-kva.example',
			"idn-\xc3\xa4.test",
			"bad\0host.test",
			'exa mple.test',
			'',
			'localhost',
		);
		$users   = array( '', 'user@', 'user:pass@', 'us%20er:p%40ss@', "u\0:p@" );
		$ports   = array( '', ':80', ':443', ':8080', ':81', ':0', ':65535', ':65536', ':bad' );
		$paths   = array( '', '/', '/a/b', '/../wp-config.php', '/%2e%2e/a', '//double', "/control\x01/x", '/file.php;param', '/space path', "/bytes/\xff" );
		$queries = array( '', '?a=1&b=2', '?x=http://example.org', "?\r\nx=y", '?array[]=1', '?q=%00', '?colon=this:that' );
		$frags   = array( '', '#frag', '#frag:colon', "#bad\x02frag" );

		$kind   = self::rng_int( $rng, 0, 4 );
		$scheme = self::rng_choice( $rng, $schemes );
		$host   = self::rng_choice( $rng, $hosts );
		$auth   = self::rng_choice( $rng, $users ) . $host . self::rng_choice( $rng, $ports );
		$tail   = self::rng_choice( $rng, $paths ) . self::rng_choice( $rng, $queries ) . self::rng_choice( $rng, $frags );

		if ( 0 === $kind ) {
			return ( '' === $scheme ? 'http' : $scheme ) . '://' . $auth . $tail;
		}
		if ( 1 === $kind ) {
			return '//' . $auth . $tail;
		}
		if ( 2 === $kind ) {
			return self::rng_choice( $rng, $paths ) . self::rng_choice( $rng, $queries ) . self::rng_choice( $rng, $frags );
		}
		if ( 3 === $kind ) {
			return ( '' === $scheme ? 'mailto' : $scheme ) . ':' . ltrim( $tail, '/' );
		}

		return $scheme . "://\t" . $auth . $tail;
	}

	private static function set_url_scheme_cases( array &$rng ): array {
		$cases = array(
			array( 'url' => '//example.com/path?x=1', 'scheme' => 'https', 'ssl' => false, 'forceSslAdmin' => false ),
			array( 'url' => 'http://example.com/a/b?c=1#frag', 'scheme' => 'https', 'ssl' => false, 'forceSslAdmin' => false ),
			array( 'url' => 'https://example.com/a/b?c=1#frag', 'scheme' => 'http', 'ssl' => true, 'forceSslAdmin' => false ),
			array( 'url' => 'ftp://example.com/file.txt', 'scheme' => 'https', 'ssl' => false, 'forceSslAdmin' => false ),
			array( 'url' => 'http://example.com//double//slash', 'scheme' => 'relative', 'ssl' => false, 'forceSslAdmin' => false ),
			array( 'url' => ' https://example.com/path with spaces ', 'scheme' => 'relative', 'ssl' => true, 'forceSslAdmin' => false ),
			array( 'url' => '/already/relative?x=1', 'scheme' => 'relative', 'ssl' => false, 'forceSslAdmin' => true ),
			array( 'url' => 'mailto:user@example.com', 'scheme' => 'relative', 'ssl' => false, 'forceSslAdmin' => false ),
			array( 'url' => 'HTTPS://EXAMPLE.com/Mixed', 'scheme' => 'http', 'ssl' => false, 'forceSslAdmin' => false ),
			array( 'url' => "http://example.com/control\x01path", 'scheme' => 'relative', 'ssl' => true, 'forceSslAdmin' => true ),
			array( 'url' => 'http://example.com/default-scheme', 'scheme' => null, 'ssl' => false, 'forceSslAdmin' => false ),
			array( 'url' => 'http://example.com/default-ssl', 'scheme' => null, 'ssl' => true, 'forceSslAdmin' => false ),
			array( 'url' => 'http://example.com/admin-http', 'scheme' => 'admin', 'ssl' => false, 'forceSslAdmin' => false ),
			array( 'url' => 'http://example.com/admin-forced', 'scheme' => 'admin', 'ssl' => false, 'forceSslAdmin' => true ),
			array( 'url' => 'http://example.com/login-ssl', 'scheme' => 'login', 'ssl' => true, 'forceSslAdmin' => false ),
			array( 'url' => 'http://example.com/login-post-forced', 'scheme' => 'login_post', 'ssl' => false, 'forceSslAdmin' => true ),
			array( 'url' => 'http://example.com/rpc-http', 'scheme' => 'rpc', 'ssl' => false, 'forceSslAdmin' => false ),
			array( 'url' => 'https://example.com/rest-invalid', 'scheme' => 'rest', 'ssl' => false, 'forceSslAdmin' => false ),
			array( 'url' => 'http://example.com/custom-invalid', 'scheme' => 'component-fuzz', 'ssl' => true, 'forceSslAdmin' => false ),
			array( 'url' => 'http://example.com/empty-invalid', 'scheme' => '', 'ssl' => false, 'forceSslAdmin' => true ),
		);

		$schemes = array( 'http', 'https', 'relative', null, 'admin', 'login', 'login_post', 'rpc', 'rest', 'component-fuzz', '' );
		for ( $i = 0; $i < 16; ++$i ) {
			$cases[] = array(
				'url'           => self::generated_url( $rng ),
				'scheme'        => self::rng_choice( $rng, $schemes ),
				'ssl'           => 1 === self::rng_int( $rng, 0, 1 ),
				'forceSslAdmin' => 1 === self::rng_int( $rng, 0, 1 ),
			);
		}

		return $cases;
	}

	private static function expected_set_url_scheme( string $url, $orig_scheme, bool $ssl, bool $force_ssl_admin ): array {
		$scheme = self::expected_normalized_url_scheme( $orig_scheme, $ssl, $force_ssl_admin );
		$url = trim( $url );
		if ( str_starts_with( $url, '//' ) ) {
			$url = 'http:' . $url;
		}

		if ( 'relative' === $scheme ) {
			$url = ltrim( (string) preg_replace( '#^\w+://[^/]*#', '', $url ) );
			if ( '' !== $url && '/' === $url[0] ) {
				$url = '/' . ltrim( $url, "/ \t\n\r\0\x0B" );
			}

			return array(
				'url'        => $url,
				'scheme'     => $scheme,
				'origScheme' => $orig_scheme,
			);
		}

		return array(
			'url'        => (string) preg_replace( '#^\w+://#', $scheme . '://', $url ),
			'scheme'     => $scheme,
			'origScheme' => $orig_scheme,
		);
	}

	private static function expected_normalized_url_scheme( $scheme, bool $ssl, bool $force_ssl_admin ): string {
		if ( ! $scheme ) {
			return $ssl ? 'https' : 'http';
		}

		if ( in_array( $scheme, array( 'admin', 'login', 'login_post', 'rpc' ), true ) ) {
			return $ssl || $force_ssl_admin ? 'https' : 'http';
		}

		if ( ! in_array( $scheme, array( 'http', 'https', 'relative' ), true ) ) {
			return $ssl ? 'https' : 'http';
		}

		return $scheme;
	}

	private static function configure_ssl_server_state( bool $ssl ): void {
		if ( $ssl ) {
			$_SERVER['HTTPS']      = 'on';
			$_SERVER['SERVER_PORT'] = '443';
		} else {
			unset( $_SERVER['HTTPS'] );
			$_SERVER['SERVER_PORT'] = '80';
		}
	}

	private static function snapshot_server_keys( array $keys ): array {
		$snapshot = array();
		foreach ( $keys as $key ) {
			$snapshot[ $key ] = array(
				'exists' => array_key_exists( $key, $_SERVER ),
				'value'  => array_key_exists( $key, $_SERVER ) ? $_SERVER[ $key ] : null,
			);
		}

		return $snapshot;
	}

	private static function restore_server_keys( array $snapshot ): void {
		foreach ( $snapshot as $key => $entry ) {
			if ( $entry['exists'] ) {
				$_SERVER[ $key ] = $entry['value'];
			} else {
				unset( $_SERVER[ $key ] );
			}
		}
	}

	private static function server_snapshot_matches( array $snapshot ): bool {
		foreach ( $snapshot as $key => $entry ) {
			$exists = array_key_exists( $key, $_SERVER );
			if ( $exists !== $entry['exists'] ) {
				return false;
			}
			if ( $exists && $_SERVER[ $key ] !== $entry['value'] ) {
				return false;
			}
		}

		return true;
	}

	private static function path_cases( array &$rng ): array {
		$cases = array(
			'',
			'.',
			'..',
			'../',
			'../file.php',
			'foo/../',
			'foo/../../bar.php',
			'/absolute/path.php',
			'C:\\Windows\\system32\\drivers\\etc\\hosts',
			'c:/temp/file.txt',
			'\\\\server\\share\\file.txt',
			'//server/share//file.txt',
			'php://filter\\resource=index.php',
			'phar://C:\\path\\file.phar/a.php',
			"a\0b",
			'folder\\sub\\file.php',
			'a//b///c',
			'C:relative',
			'Z:/mixed\\slashes',
			'.../file',
			'foo/..bar/baz',
			'./safe/file',
			'safe/../',
			'safe/../../',
			"\t/controls\n",
		);

		$segments = array( 'safe', '..', '.', 'folder', 'file.php', "nul\0seg", 'C:', 'name with space', 'aux', 'con' );
		$slashes  = array( '/', '\\', '//', '\\\\' );
		for ( $i = 0; $i < 24; ++$i ) {
			$path = '';
			$count = self::rng_int( $rng, 1, 5 );
			for ( $j = 0; $j < $count; ++$j ) {
				if ( '' !== $path ) {
					$path .= self::rng_choice( $rng, $slashes );
				}
				$path .= self::rng_choice( $rng, $segments );
			}
			if ( 0 === self::rng_int( $rng, 0, 4 ) ) {
				$path = '/' . $path;
			}
			$cases[] = $path;
		}

		return array_values( array_unique( $cases ) );
	}

	private static function filename_cases( array &$rng ): array {
		$cases = array(
			'image.jpg',
			'IMAGE.JPG',
			'avatar-150x150.PNG',
			'avatar-scaled.jpg',
			'evil.php.jpg',
			'shell.php',
			'archive.tar.gz',
			'noext',
			'..',
			'.htaccess',
			'a/b.jpg',
			'a\\b.png',
			"nul\0byte.gif",
			"line\nbreak.txt",
			"resume-\xc3\xa9.pdf",
			"photo.\xff.jpg",
			'double..dots..jpg',
			'web.config',
			'file.svg',
			'file.SVG',
			'heic.HEIC',
			'image.jpeg.php',
			'unnamed',
			'jpg',
			'%20space+name.png',
			'hello   world.txt',
			'thumb-rotated.webp',
			'document.docx',
			'sheet.xlsx',
			'audio.mp3',
			'video.MOV',
			'image.avif',
		);

		$bases = array( 'photo', 'index', 'my file', 'a/b', 'a\\b', "ctrl\x01name", "nul\0name", 'resume', 'avatar-300x200', 'rotated-scaled' );
		$mids  = array( '', '.php', '.tar', '..', '.backup', '.JPG', '.svg' );
		$exts  = array( '.jpg', '.JPG', '.png', '.txt', '.php', '.gif', '.webp', '.heic', '.pdf', '' );
		for ( $i = 0; $i < 32; ++$i ) {
			$cases[] = self::rng_choice( $rng, $bases ) . self::rng_choice( $rng, $mids ) . self::rng_choice( $rng, $exts );
		}

		return array_values( array_unique( $cases ) );
	}

	private static function quota_cases( array &$rng ): array {
		$cases = array(
			array(
				'label'          => 'disabled-check-uses-size-cap-only',
				'blogSpace'      => 25,
				'siteSpace'      => 100,
				'usedSpace'      => 40,
				'disabled'       => 1,
				'fileuploadMaxK' => 2048,
				'inputLimit'     => 8 * MB_IN_BYTES,
			),
			array(
				'label'          => 'under-quota-limits-by-remaining-space',
				'blogSpace'      => 40,
				'siteSpace'      => 100,
				'usedSpace'      => 12,
				'disabled'       => 0,
				'fileuploadMaxK' => 32768,
				'inputLimit'     => 50 * MB_IN_BYTES,
			),
			array(
				'label'          => 'over-quota-blocks-uploads',
				'blogSpace'      => 10,
				'siteSpace'      => 100,
				'usedSpace'      => 15,
				'disabled'       => 0,
				'fileuploadMaxK' => 4096,
				'inputLimit'     => 10 * MB_IN_BYTES,
			),
			array(
				'label'          => 'site-option-fallback',
				'blogSpace'      => 'not-numeric',
				'siteSpace'      => 33,
				'usedSpace'      => 5,
				'disabled'       => 0,
				'fileuploadMaxK' => 1024,
				'inputLimit'     => 2 * MB_IN_BYTES,
			),
			array(
				'label'          => 'negative-quota-clamps-availability',
				'blogSpace'      => -5,
				'siteSpace'      => 100,
				'usedSpace'      => 1,
				'disabled'       => 0,
				'fileuploadMaxK' => 1024,
				'inputLimit'     => 2 * MB_IN_BYTES,
			),
		);

		for ( $i = 0; $i < 8; ++$i ) {
			$cases[] = array(
				'label'          => 'generated-quota-' . $i,
				'blogSpace'      => self::rng_choice( $rng, array( self::rng_int( $rng, -3, 120 ), (string) self::rng_int( $rng, 0, 120 ), 'bad' ) ),
				'siteSpace'      => self::rng_int( $rng, 1, 150 ),
				'usedSpace'      => self::rng_int( $rng, 0, 180 ),
				'disabled'       => self::rng_int( $rng, 0, 4 ) === 0 ? 1 : 0,
				'fileuploadMaxK' => self::rng_int( $rng, 1, 65536 ),
				'inputLimit'     => self::rng_int( $rng, 1, 96 ) * MB_IN_BYTES,
			);
		}

		return $cases;
	}

	private static function upload_policy_cases( array &$rng ): array {
		$cases = array(
			array(
				'label'           => 'default-image-types-allow-under-limit',
				'uploadFiletypes' => 'jpg jpeg png gif',
				'fileuploadMaxK'  => 4,
				'bytes'           => 4 * KB_IN_BYTES,
				'disabled'        => false,
				'name'            => 'under-limit.jpg',
			),
			array(
				'label'           => 'pdf-text-types-block-over-limit',
				'uploadFiletypes' => 'pdf txt',
				'fileuploadMaxK'  => 1,
				'bytes'           => KB_IN_BYTES + 1,
				'disabled'        => false,
				'name'            => 'too-large.pdf',
			),
			array(
				'label'           => 'disabled-size-check-allows-over-limit',
				'uploadFiletypes' => 'webp gz',
				'fileuploadMaxK'  => 1,
				'bytes'           => 3 * KB_IN_BYTES,
				'disabled'        => true,
				'name'            => 'disabled.webp',
			),
			array(
				'label'           => 'empty-network-types-allow-no-mimes',
				'uploadFiletypes' => '',
				'fileuploadMaxK'  => 8,
				'bytes'           => 0,
				'disabled'        => false,
				'name'            => 'empty.bin',
			),
			array(
				'label'           => 'substring-network-type-matches-patterns',
				'uploadFiletypes' => 'g',
				'fileuploadMaxK'  => 2,
				'bytes'           => 2 * KB_IN_BYTES + 1,
				'disabled'        => false,
				'name'            => 'substring.gif',
			),
		);

		$tokens = array( 'jpg', 'png', 'pdf', 'txt', 'component-fuzz', 'missing', 'gz', 'webp' );
		for ( $i = 0; $i < 6; ++$i ) {
			$allowed = array();
			$count   = self::rng_int( $rng, 0, 4 );
			for ( $j = 0; $j < $count; ++$j ) {
				$allowed[] = self::rng_choice( $rng, $tokens );
			}

			$max_kb  = self::rng_int( $rng, 1, 12 );
			$cases[] = array(
				'label'           => 'generated-upload-policy-' . $i,
				'uploadFiletypes' => implode( ' ', $allowed ),
				'fileuploadMaxK'  => $max_kb,
				'bytes'           => self::rng_int( $rng, 0, ( $max_kb + 2 ) * KB_IN_BYTES ),
				'disabled'        => (bool) self::rng_int( $rng, 0, 1 ),
				'name'            => 'generated-' . $i . '.' . self::rng_choice( $rng, array( 'jpg', 'pdf', 'webp', 'bin' ) ),
			);
		}

		return $cases;
	}

	private static function check_upload_size_cases( array &$rng ): array {
		$cases = array(
			array(
				'label'          => 'disabled-check-leaves-large-file-alone',
				'name'           => 'disabled-large.bin',
				'fileSize'       => 3 * KB_IN_BYTES,
				'inputError'     => 0,
				'allowedMb'      => 1,
				'usedMb'         => 2,
				'disabled'       => 1,
				'fileuploadMaxK' => 1,
				'expected'       => 'ok',
			),
			array(
				'label'          => 'preexisting-upload-error-is-preserved',
				'name'           => 'already-failed.bin',
				'fileSize'       => 512,
				'inputError'     => UPLOAD_ERR_NO_FILE,
				'allowedMb'      => 1,
				'usedMb'         => 0,
				'disabled'       => 0,
				'fileuploadMaxK' => 1,
				'expected'       => 'ok',
			),
			array(
				'label'          => 'small-file-under-limits',
				'name'           => 'small.bin',
				'fileSize'       => 512,
				'inputError'     => 0,
				'allowedMb'      => 5,
				'usedMb'         => 1,
				'disabled'       => 0,
				'fileuploadMaxK' => 8,
				'expected'       => 'ok',
			),
			array(
				'label'          => 'fileupload-max-k-blocks-large-file',
				'name'           => 'too-large.bin',
				'fileSize'       => 4 * KB_IN_BYTES,
				'inputError'     => 0,
				'allowedMb'      => 10,
				'usedMb'         => 0,
				'disabled'       => 0,
				'fileuploadMaxK' => 1,
				'expected'       => 'error',
			),
			array(
				'label'          => 'over-quota-blocks-even-small-file',
				'name'           => 'over-quota.bin',
				'fileSize'       => 256,
				'inputError'     => 0,
				'allowedMb'      => 1,
				'usedMb'         => 2,
				'disabled'       => 0,
				'fileuploadMaxK' => 16,
				'expected'       => 'error',
			),
		);

		for ( $i = 0; $i < 4; ++$i ) {
			$allowed  = self::rng_int( $rng, 1, 8 );
			$used     = self::rng_int( $rng, 0, 10 );
			$file_kb  = self::rng_int( $rng, 1, 12 );
			$max_kb   = self::rng_int( $rng, 1, 12 );
			$disabled = self::rng_int( $rng, 0, 5 ) === 0 ? 1 : 0;
			$will_error = ! $disabled && ( $used >= $allowed || $file_kb > $max_kb );

			$cases[] = array(
				'label'          => 'generated-check-upload-size-' . $i,
				'name'           => 'generated-' . $i . '.bin',
				'fileSize'       => $file_kb * KB_IN_BYTES,
				'inputError'     => 0,
				'allowedMb'      => $allowed,
				'usedMb'         => $used,
				'disabled'       => $disabled,
				'fileuploadMaxK' => $max_kb,
				'expected'       => $will_error ? 'error' : 'ok',
			);
		}

		return $cases;
	}

	private static function quota_expectations( array $case ): array {
		if ( is_numeric( $case['blogSpace'] ) ) {
			$allowed_return = $case['blogSpace'];
		} elseif ( is_numeric( $case['siteSpace'] ) ) {
			$allowed_return = $case['siteSpace'];
		} else {
			$allowed_return = 100;
		}

		$allowed_numeric   = (int) $allowed_return;
		$clamped_allowed   = max( 0, $allowed_numeric );
		$space_allowed    = $clamped_allowed * MB_IN_BYTES;
		$space_used       = (int) $case['usedSpace'] * MB_IN_BYTES;
		$disabled         = (bool) $case['disabled'];
		$available_bytes  = $disabled ? $space_allowed : max( 0, $space_allowed - $space_used );
		$fileupload_bytes = (int) $case['fileuploadMaxK'] * KB_IN_BYTES;
		$upload_limit     = min( (int) $case['inputLimit'], $fileupload_bytes );
		if ( ! $disabled ) {
			$upload_limit = min( $upload_limit, $available_bytes );
		}

		return array(
			'allowedMb'      => $allowed_return,
			'availableBytes' => $available_bytes,
			'hasSpace'       => $disabled ? true : (bool) $available_bytes,
			'uploadLimit'    => $upload_limit,
			'overQuota'      => $disabled ? false : ( ( $allowed_numeric - (int) $case['usedSpace'] ) < 0 ),
		);
	}

	private static function expected_upload_mimes( array $mimes, string $upload_filetypes ): array {
		$site_exts  = explode( ' ', $upload_filetypes );
		$site_mimes = array();

		foreach ( $site_exts as $ext ) {
			foreach ( $mimes as $ext_pattern => $mime ) {
				if ( '' !== $ext && str_contains( $ext_pattern, $ext ) ) {
					$site_mimes[ $ext_pattern ] = $mime;
				}
			}
		}

		return $site_mimes;
	}

	private static function custom_mime_filetype_cases( array &$rng ): array {
		$cases = array(
			array(
				'filename'     => 'photo.jpg',
				'mimes'        => array( 'jpg|jpeg' => 'image/jpeg' ),
				'expectedExt'  => 'jpg',
				'expectedType' => 'image/jpeg',
			),
			array(
				'filename'     => 'photo.JPG',
				'mimes'        => array( 'jpg|jpeg' => 'image/jpeg' ),
				'expectedExt'  => 'JPG',
				'expectedType' => 'image/jpeg',
			),
			array(
				'filename'     => 'archive.tar.gz',
				'mimes'        => array( 'tar\.gz|tgz' => 'application/gzip' ),
				'expectedExt'  => 'tar.gz',
				'expectedType' => 'application/gzip',
			),
			array(
				'filename'     => 'vector.svg',
				'mimes'        => array( 'jpg|jpeg' => 'image/jpeg' ),
				'expectedExt'  => false,
				'expectedType' => false,
			),
		);

		$exts = array( 'cfz', 'data', 'asset', 'bundle' );
		for ( $i = 0; $i < 6; ++$i ) {
			$ext       = self::rng_choice( $rng, $exts );
			$uppercase = 0 === self::rng_int( $rng, 0, 2 );
			$filename  = 'component-' . self::rng_int( $rng, 100, 999 ) . '.' . ( $uppercase ? strtoupper( $ext ) : $ext );

			$cases[] = array(
				'filename'     => $filename,
				'mimes'        => array( preg_quote( $ext, '!' ) => 'application/x-component-fuzz-' . $ext ),
				'expectedExt'  => $uppercase ? strtoupper( $ext ) : $ext,
				'expectedType' => 'application/x-component-fuzz-' . $ext,
			);
		}

		return $cases;
	}

	private static function generated_unique_filename_cases( array &$rng ): array {
		$cases = array();
		$bases = array( 'photo', 'image', 'thumb-150x150', 'thumb-scaled', 'report final', 'a/b', 'archive.tar', 'resume' );
		$exts  = array( '.jpg', '.JPG', '.png', '.PNG', '.txt', '.pdf', '.webp', '.WEBP', '.gz' );

		for ( $i = 0; $i < 12; ++$i ) {
			$cases[] = self::rng_choice( $rng, $bases ) . self::rng_choice( $rng, $exts );
		}

		return $cases;
	}

	private static function make_temp_root( int $seed, array &$result ): ?string {
		$base = rtrim( sys_get_temp_dir(), DIRECTORY_SEPARATOR );
		if ( '' === $base || ! is_dir( $base ) || ! is_writable( $base ) ) {
			return null;
		}

		$prefix = $base . DIRECTORY_SEPARATOR . 'component-fuzz-network-' . getmypid() . '-' . $seed . '-';
		for ( $i = 0; $i < 100; ++$i ) {
			$dir = $prefix . $i;
			if ( @mkdir( $dir, 0700, true ) ) {
				self::feature( $result, 'temp-files' );
				return $dir;
			}
			if ( is_dir( $dir ) ) {
				continue;
			}
		}

		return null;
	}

	private static function ensure_dir( string $path ): void {
		if ( ! is_dir( $path ) ) {
			mkdir( $path, 0777, true );
		}
	}

	private static function remove_dir_recursive( string $path ): void {
		$base = rtrim( sys_get_temp_dir(), DIRECTORY_SEPARATOR ) . DIRECTORY_SEPARATOR . 'component-fuzz-network-';
		if ( 0 !== strpos( $path, $base ) || ! is_dir( $path ) ) {
			return;
		}

		$items = scandir( $path );
		if ( false !== $items ) {
			foreach ( $items as $item ) {
				if ( '.' === $item || '..' === $item ) {
					continue;
				}
				$child = $path . DIRECTORY_SEPARATOR . $item;
				if ( is_dir( $child ) && ! is_link( $child ) ) {
					self::remove_dir_recursive( $child );
				} else {
					@unlink( $child );
				}
			}
		}

		@rmdir( $path );
	}

	private static function load_admin_file_helpers(): void {
		if ( function_exists( 'wp_handle_sideload' ) || ! defined( 'ABSPATH' ) ) {
			return;
		}

		$file = rtrim( ABSPATH, '/\\' ) . '/wp-admin/includes/file.php';
		if ( is_file( $file ) ) {
			require_once $file;
		}
	}

	private static function load_admin_multisite_helpers(): void {
		if ( function_exists( 'upload_is_user_over_quota' ) || ! defined( 'ABSPATH' ) ) {
			return;
		}

		$file = rtrim( ABSPATH, '/\\' ) . '/wp-admin/includes/ms.php';
		if ( is_file( $file ) ) {
			require_once $file;
		}
	}

	private static function normalize_directory_separators( string $path ): string {
		$path = str_replace( '\\', '/', $path );
		return (string) preg_replace( '|(?<=.)/+|', '/', $path );
	}

	private static function describe_value( $value ) {
		if ( is_string( $value ) ) {
			$preview = substr( $value, 0, 160 );
			$json    = json_encode( $preview, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE );
			if ( false === $json ) {
				$json = null;
			}

			return array(
				'type'       => 'string',
				'length'     => strlen( $value ),
				'sha1'       => sha1( $value ),
				'preview'    => $json,
				'hexPreview' => bin2hex( substr( $value, 0, 48 ) ),
			);
		}

		if ( is_array( $value ) ) {
			$out   = array();
			$count = 0;
			foreach ( $value as $key => $item ) {
				if ( $count >= 24 ) {
					$out['__truncated__'] = count( $value ) - $count;
					break;
				}
				$out[ is_int( $key ) ? $key : (string) $key ] = self::describe_value( $item );
				++$count;
			}
			return $out;
		}

		if ( is_object( $value ) ) {
			return array(
				'type'  => 'object',
				'class' => get_class( $value ),
			);
		}

		if ( is_resource( $value ) ) {
			return array(
				'type' => 'resource',
			);
		}

		return $value;
	}
}
