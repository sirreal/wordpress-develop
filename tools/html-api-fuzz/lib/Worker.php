<?php
namespace HtmlApiFuzz;

class Worker {
	public static function run( array $options ): array {
		$output_dir = option_string( $options, 'output-dir', getcwd() . DIRECTORY_SEPARATOR . 'html-api-fuzz-worker' );
		ensure_dir( $output_dir );

		$seed    = option_int( $options, 'seed', 1 );
		$profile = option_string( $options, 'profile', 'auto' );
		$mode    = option_string( $options, 'mode', 'auto' );

		if ( null !== option_string( $options, 'input-base64', null ) ) {
			$input = base64_decode( option_string( $options, 'input-base64' ), true );
			if ( false === $input ) {
				throw new \InvalidArgumentException( 'Invalid --input-base64.' );
			}
			$profile = option_string( $options, 'profile', 'replay' );
			$mode    = option_string( $options, 'mode', Generator::MODE_FRAGMENT_BODY );
		} elseif ( null !== option_string( $options, 'input-file', null ) ) {
			$input = file_get_contents( option_string( $options, 'input-file' ) );
			if ( false === $input ) {
				throw new \InvalidArgumentException( 'Could not read --input-file.' );
			}
			$profile = option_string( $options, 'profile', 'replay' );
			$mode    = option_string( $options, 'mode', Generator::MODE_FRAGMENT_BODY );
		} else {
			$generated = Generator::generate( $seed, $profile ?? 'auto', $mode ?? 'auto' );
			$input     = $generated['input'];
			$profile   = $generated['profile'];
			$mode      = $generated['mode'];
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

		$replay = self::base_replay( $seed, $profile, $mode, $input, $output_dir, $limits, $fail_unsupported );
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
			$result['status']       = 'failed';
			$result['failureClass'] = 'tag-invariant-failed';
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

	private static function base_replay( int $seed, string $profile, string $mode, string $input, string $output_dir, array $limits, bool $fail_unsupported ): array {
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

		if ( empty( $diff['wordpressHex'] ) || empty( $diff['domHex'] ) || $diff['wordpressHex'] === $diff['domHex'] ) {
			return false;
		}

		if ( ( $diff['wordpressNorm'] ?? null ) === ( $diff['domNorm'] ?? null ) ) {
			return true;
		}

		$wordpress_line = $diff['wordpressLine'] ?? null;
		$dom_line       = $diff['domLine'] ?? null;
		if ( is_string( $wordpress_line ) && is_string( $dom_line ) && function_exists( 'wp_scrub_utf8' ) ) {
			return wp_scrub_utf8( $wordpress_line ) === $dom_line;
		}

		return false;
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
