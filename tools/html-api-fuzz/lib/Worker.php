<?php
namespace HtmlApiFuzz;

class Worker {
	public static function run( array $options ): array {
		$output_dir = option_string( $options, 'output-dir', getcwd() . DIRECTORY_SEPARATOR . 'html-api-fuzz-worker' );
		ensure_dir( $output_dir );

		$seed    = option_int( $options, 'seed', 1 );
		$profile = option_string( $options, 'profile', 'auto' );
		$mode    = option_string( $options, 'mode', 'auto' );
		$payload_policy_option = option_string( $options, 'payload-policy', null );
		$payload_policy        = $payload_policy_option ?? 'auto';
		$max_input_bytes_value = option_int( $options, 'max-input-bytes', 0 );
		$max_input_bytes       = $max_input_bytes_value > 0 ? $max_input_bytes_value : null;
		$generator_parameters  = null;
		$input_source          = 'generated';

		if ( null !== option_string( $options, 'input-base64', null ) ) {
			$input = base64_decode( option_string( $options, 'input-base64' ), true );
			if ( false === $input ) {
				throw new \InvalidArgumentException( 'Invalid --input-base64.' );
			}
			$profile = option_string( $options, 'profile', 'replay' );
			$mode    = option_string( $options, 'mode', Generator::MODE_FRAGMENT_BODY );
			$payload_policy = $payload_policy_option;
			$input_source   = 'input-base64';
			self::validate_profile_metadata( $profile );
			self::validate_mode_metadata( $mode );
			self::validate_payload_policy_metadata( $payload_policy );
		} elseif ( null !== option_string( $options, 'input-file', null ) ) {
			$input = file_get_contents( option_string( $options, 'input-file' ) );
			if ( false === $input ) {
				throw new \InvalidArgumentException( 'Could not read --input-file.' );
			}
			$profile = option_string( $options, 'profile', 'replay' );
			$mode    = option_string( $options, 'mode', Generator::MODE_FRAGMENT_BODY );
			$payload_policy = $payload_policy_option;
			$input_source   = 'input-file';
			self::validate_profile_metadata( $profile );
			self::validate_mode_metadata( $mode );
			self::validate_payload_policy_metadata( $payload_policy );
		} else {
			$generated            = Generator::generate( $seed, $profile ?? 'auto', $mode ?? 'auto', $payload_policy ?? 'auto', $max_input_bytes );
			$input                = $generated['input'];
			$profile              = $generated['profile'];
			$mode                 = $generated['mode'];
			$payload_policy       = $generated['payloadPolicy'];
			$generator_parameters = $generated['parameters'];
		}

		$limits = array(
			'maxTokens' => option_int( $options, 'max-tokens', 2000 ),
			'maxNodes'  => option_int( $options, 'max-nodes', 3000 ),
		);
		$fail_unsupported = option_bool( $options, 'fail-unsupported', false );

		$replay_path = $output_dir . DIRECTORY_SEPARATOR . 'replay.json';
		$result_path = $output_dir . DIRECTORY_SEPARATOR . 'result.json';
		$input_path  = $output_dir . DIRECTORY_SEPARATOR . 'input.bin';
		file_put_contents( $input_path, $input );

		$replay = self::base_replay( $seed, $profile, $mode, $payload_policy, $generator_parameters, $input_source, $input, $output_dir, $limits, $fail_unsupported );
		write_json_file( $replay_path, $replay );

		$tag_result = TagInvariants::check( $input, $limits );
		$wp_result  = TreeRenderer::render_wordpress( $input, $mode, $limits );
		$dom_result = array( 'status' => TreeRenderer::STATUS_ERROR, 'error' => 'Not run.' );

		$result = array(
			'schemaVersion' => 1,
			'kind'          => 'html-api-fuzz-worker-result',
			'createdAt'     => gmdate( 'c' ),
			'ok'            => true,
			'status'        => 'passed',
			'seed'          => $seed,
			'profile'       => $profile,
			'mode'          => $mode,
			'payloadPolicy' => $payload_policy,
			'generator'     => $generator_parameters,
			'inputSource'   => $input_source,
			'inputSha1'     => sha1( $input ),
			'inputLength'   => strlen( $input ),
			'inputPreview'  => preview_bytes( $input ),
			'paths'         => array(
				'outputDir'  => $output_dir,
				'inputPath'  => $input_path,
				'replayPath' => $replay_path,
				'resultPath' => $result_path,
			),
			'tagProcessor'  => $tag_result,
			'wordpress'     => self::compact_parse_result( $wp_result, $output_dir, 'wordpress-tree.txt' ),
			'dom'           => $dom_result,
			'comparison'    => null,
		);

		if ( ! $tag_result['ok'] ) {
			$result['ok']           = false;
			$result['failureClass'] = self::tag_invariant_failure_class( $tag_result );
			$result['status']       = 'resource-limit' === $result['failureClass'] ? 'resource-limit' : 'failed';
		} elseif ( TreeRenderer::STATUS_UNSUPPORTED === $wp_result['status'] ) {
			$result['status']       = 'unsupported';
			$result['failureClass'] = 'unsupported';
			if ( $fail_unsupported ) {
				$result['ok'] = false;
			}
		} elseif ( TreeRenderer::STATUS_ERROR === $wp_result['status'] ) {
			$result['ok']           = false;
			$result['status']       = 'failed';
			$result['failureClass'] = $wp_result['failureClass'] ?? 'wordpress-parse-error';
		} else {
			try {
				$dom_result = TreeRenderer::render_dom( $input, $mode, $limits );
			} catch ( \Throwable $e ) {
				$dom_result = array(
					'status'       => TreeRenderer::STATUS_ERROR,
					'error'        => $e->getMessage(),
					'throwable'    => get_class( $e ),
					'failureClass' => 'oracle-renderer-error',
				);
			}
			$result['dom'] = self::compact_parse_result( $dom_result, $output_dir, 'dom-tree.txt' );

			if ( TreeRenderer::STATUS_ERROR === $dom_result['status'] ) {
				$result['failureClass'] = $dom_result['failureClass'] ?? 'oracle-renderer-error';
				$result['status']       = 'oracle-parse-error' === $result['failureClass'] ? 'oracle-parse-error' : 'failed';
				if ( 'oracle-parse-error' !== $result['failureClass'] ) {
					$result['ok'] = false;
				}
			} else {
				$comparison = TreeRenderer::compare_trees( $wp_result['tree'], $dom_result['tree'] );
				$result['comparison'] = $comparison;
				if ( ! $comparison['ok'] ) {
					$result['ok']           = false;
					$result['status']       = 'failed';
					$result['failureClass'] = self::is_encoding_mismatch( $input, $comparison['firstDifference'] ?? array() )
						? 'encoding-mismatch'
						: 'tree-mismatch';
				}
			}
		}

		$signature = Signature::from_result( $result );
		if ( null !== $signature ) {
			$result['signature'] = $signature;
		}

		$replay['result']    = array(
			'ok'           => $result['ok'],
			'status'       => $result['status'],
			'failureClass' => $result['failureClass'] ?? null,
			'signature'    => $signature,
			'resultPath'   => $result_path,
		);
		$replay['signature'] = $signature;
		write_json_file( $replay_path, $replay );
		write_json_file( $result_path, $result );

		return $result;
	}

	private static function validate_payload_policy_metadata( ?string $payload_policy ): void {
		if ( null !== $payload_policy && ! in_array( $payload_policy, Generator::payload_policies(), true ) ) {
			throw new \InvalidArgumentException( 'Unknown generator payload policy: ' . $payload_policy );
		}
	}

	private static function validate_profile_metadata( string $profile ): void {
		if ( 'replay' !== $profile && ! in_array( $profile, Generator::profiles(), true ) ) {
			throw new \InvalidArgumentException( 'Unknown generator profile: ' . $profile );
		}
	}

	private static function validate_mode_metadata( string $mode ): void {
		if ( ! in_array( $mode, Generator::modes(), true ) ) {
			throw new \InvalidArgumentException( 'Unknown generator mode: ' . $mode );
		}
	}

	private static function base_replay( int $seed, string $profile, string $mode, ?string $payload_policy, ?array $generator_parameters, string $input_source, string $input, string $output_dir, array $limits, bool $fail_unsupported ): array {
		return array(
			'schemaVersion' => 1,
			'kind'          => 'html-api-fuzz-replay',
			'createdAt'     => gmdate( 'c' ),
			'repoRoot'      => repo_root(),
			'repoCommit'    => trim( (string) @shell_exec( 'git -C ' . escapeshellarg( repo_root() ) . ' rev-parse HEAD 2>/dev/null' ) ),
			'phpVersion'    => PHP_VERSION,
			'seed'          => $seed,
			'profile'       => $profile,
			'mode'          => $mode,
			'payloadPolicy' => $payload_policy,
			'generator'     => $generator_parameters,
			'inputSource'   => $input_source,
			'inputBase64'   => base64_encode( $input ),
			'inputSha1'     => sha1( $input ),
			'inputLength'   => strlen( $input ),
			'inputPreview'  => preview_bytes( $input ),
			'limits'        => $limits,
			'options'       => array(
				'failUnsupported' => $fail_unsupported,
			),
			'command'       => array(
				'program' => PHP_BINARY,
				'args'    => array(
					'tools/html-api-fuzz/replay.php',
					'--replay',
					$output_dir . DIRECTORY_SEPARATOR . 'replay.json',
				),
				'cwd'     => repo_root(),
			),
		);
	}

	private static function is_encoding_mismatch( string $input, array $diff ): bool {
		if ( function_exists( 'wp_is_valid_utf8' ) && wp_is_valid_utf8( $input ) ) {
			return false;
		}

		$wordpress_line = $diff['wordpressLine'] ?? null;
		$dom_line       = $diff['domLine'] ?? null;
		if ( is_string( $wordpress_line ) && is_string( $dom_line ) && $wordpress_line !== $dom_line ) {
			if ( function_exists( 'wp_scrub_utf8' ) && wp_scrub_utf8( $wordpress_line ) === $dom_line ) {
				return true;
			}
		}

		if ( empty( $diff['wordpressHex'] ) || empty( $diff['domHex'] ) || $diff['wordpressHex'] === $diff['domHex'] ) {
			return false;
		}

		return false;
	}

	private static function tag_invariant_failure_class( array $tag_result ): string {
		$failures = $tag_result['failures'] ?? array();
		if ( empty( $failures ) ) {
			return 'tag-invariant-failed';
		}

		$resource_limit_names = array( 'tag-token-limit-exceeded', 'mutation-token-limit-exceeded' );
		foreach ( $failures as $failure ) {
			if ( ! in_array( $failure['name'] ?? null, $resource_limit_names, true ) ) {
				return 'tag-invariant-failed';
			}
		}

		return 'resource-limit';
	}

	private static function compact_parse_result( array $parse_result, string $output_dir, string $tree_filename ): array {
		if ( isset( $parse_result['tree'] ) ) {
			$tree_path = $output_dir . DIRECTORY_SEPARATOR . $tree_filename;
			file_put_contents( $tree_path, $parse_result['tree'] );
			$parse_result['treePath']    = $tree_path;
			$parse_result['treeSha1']    = sha1( $parse_result['tree'] );
			$parse_result['treePreview'] = preview_bytes( $parse_result['tree'], 400 );
			unset( $parse_result['tree'] );
		}
		return $parse_result;
	}
}
