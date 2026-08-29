<?php
namespace ComponentFuzz;

final class SurfaceRunner {
	/** @var array<string,string> */
	private array $surfaces;

	/**
	 * @param array<string,string> $surfaces Map of surface names to class names.
	 */
	public function __construct( array $surfaces ) {
		$this->surfaces = $surfaces;
	}

	public function surface_names(): array {
		return array_keys( $this->surfaces );
	}

	public function run( array $options ): array {
		WpBootstrap::load();

		$seed        = option_int( $options, 'seed', 1 );
		$iterations  = max( 1, option_int( $options, 'iterations', 25 ) );
		$output_dir  = option_string( $options, 'output-dir', repo_root() . DIRECTORY_SEPARATOR . 'artifacts' . DIRECTORY_SEPARATOR . 'component-fuzz' . DIRECTORY_SEPARATOR . 'run-' . gmdate( 'Ymd\THis\Z' ) );
		$fail_fast   = option_bool( $options, 'fail-fast', false );
		$surface_arg = option_string( $options, 'surface', 'all' );
		$selected    = $this->select_surfaces( $surface_arg );

		ensure_dir( $output_dir );
		$results_path = $output_dir . DIRECTORY_SEPARATOR . 'results.ndjson';
		$summary_path = $output_dir . DIRECTORY_SEPARATOR . 'summary.json';

		$summary = array(
			'kind'       => 'component-fuzz-summary',
			'createdAt'  => gmdate( 'c' ),
			'seed'       => $seed,
			'iterations' => $iterations,
			'surfaces'   => array_keys( $selected ),
			'counts'     => array(
				'passed'  => 0,
				'failed'  => 0,
				'skipped' => 0,
				'errored' => 0,
			),
			'failures'   => array(),
		);

		foreach ( $selected as $surface => $class ) {
			if ( ! class_exists( $class ) ) {
				$row = $this->error_row( $seed, $surface, 0, 'surface-class-missing', "Class {$class} is not loaded." );
				append_ndjson( $results_path, $row );
				$this->record_row( $summary, $row );
				continue;
			}

			for ( $i = 0; $i < $iterations; $i++ ) {
				$case_seed = Prng::mix_seed( $seed + $i, $surface );
				$ctx       = new FuzzContext( $case_seed, $surface, $i );
				$started   = hrtime( true );
				$rows      = $this->run_surface_case( $class, $ctx );
				foreach ( $rows as $row ) {
					$row['durationMs'] = round( ( hrtime( true ) - $started ) / 1000000, 3 );
					append_ndjson( $results_path, $row );
					$this->record_row( $summary, $row );
					if ( $fail_fast && empty( $row['ok'] ) ) {
						write_json_file( $summary_path, $summary );
						return $summary;
					}
				}
			}
		}

		write_json_file( $summary_path, $summary );
		return $summary;
	}

	private function select_surfaces( string $surface_arg ): array {
		if ( 'all' === $surface_arg ) {
			return $this->surfaces;
		}

		$selected = array();
		foreach ( array_filter( array_map( 'trim', explode( ',', $surface_arg ) ) ) as $name ) {
			if ( ! isset( $this->surfaces[ $name ] ) ) {
				throw new \InvalidArgumentException( "Unknown surface: {$name}" );
			}
			$selected[ $name ] = $this->surfaces[ $name ];
		}
		return $selected;
	}

	private function run_surface_case( string $class, FuzzContext $ctx ): array {
		set_error_handler(
			static function ( int $severity, string $message, string $file, int $line ): bool {
				if ( error_reporting() & $severity ) {
					throw new \ErrorException( $message, 0, $severity, $file, $line );
				}
				return false;
			}
		);

		try {
			$rows = $class::run( $ctx );
		} catch ( \Throwable $e ) {
			$rows = array(
				$this->error_row( $ctx->seed(), $ctx->surface(), $ctx->iteration(), 'throwable', $e->getMessage(), $e ),
			);
		} finally {
			restore_error_handler();
		}

		if ( ! is_array( $rows ) ) {
			return array(
				$this->error_row( $ctx->seed(), $ctx->surface(), $ctx->iteration(), 'invalid-return', 'Surface did not return an array.' ),
			);
		}

		if ( $this->is_aggregate_result( $rows ) ) {
			return $this->aggregate_to_rows( $rows, $ctx );
		}

		$normalized = array();
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				$normalized[] = $this->error_row( $ctx->seed(), $ctx->surface(), $ctx->iteration(), 'invalid-row', 'Surface returned a non-array row.' );
				continue;
			}
			$normalized[] = array_merge(
				array(
					'ok'        => true,
					'status'    => 'passed',
					'surface'   => $ctx->surface(),
					'invariant' => 'unspecified',
					'seed'      => $ctx->seed(),
					'iteration' => $ctx->iteration(),
					'data'      => array(),
				),
				$row
			);
		}

		return $normalized;
	}

	private function is_aggregate_result( array $result ): bool {
		return isset( $result['surface'] )
			&& ! array_is_list( $result )
			&& (
				( isset( $result['checks'] ) && is_array( $result['checks'] ) )
				|| ( isset( $result['kind'] ) && 'component-fuzz-surface-result' === $result['kind'] )
				|| ( array_key_exists( 'ok', $result ) && array_key_exists( 'failures', $result ) )
			);
	}

	private function aggregate_to_rows( array $result, FuzzContext $ctx ): array {
		if ( ! isset( $result['checks'] ) || ! is_array( $result['checks'] ) ) {
			return $this->compact_aggregate_to_rows( $result, $ctx );
		}

		$failures_by_check = array();
		foreach ( $result['failures'] ?? array() as $failure ) {
			if ( ! is_array( $failure ) ) {
				continue;
			}
			$check = $failure['check'] ?? 'aggregate';
			$failures_by_check[ $check ][] = $failure;
		}

		$rows = array();
		foreach ( $result['checks'] as $check ) {
			if ( ! is_array( $check ) ) {
				$rows[] = $this->error_row( $ctx->seed(), $ctx->surface(), $ctx->iteration(), 'invalid-aggregate-check', 'Aggregate surface returned a non-array check.' );
				continue;
			}

			$name     = (string) ( $check['name'] ?? 'aggregate-check' );
			$ok       = (bool) ( $check['ok'] ?? false );
			$failures = $failures_by_check[ $name ] ?? array();
			unset( $check['name'], $check['ok'] );

			$rows[] = array(
				'ok'        => $ok,
				'status'    => $ok ? 'passed' : 'failed',
				'surface'   => (string) ( $result['surface'] ?? $ctx->surface() ),
				'invariant' => $name,
				'seed'      => (int) ( $result['seed'] ?? $ctx->seed() ),
				'iteration' => $ctx->iteration(),
				'data'      => array(
					'check'    => $check,
					'failures' => array_slice( $failures, 0, 5 ),
				),
			);
		}

		foreach ( $result['skipped'] ?? array() as $skipped ) {
			if ( ! is_array( $skipped ) ) {
				continue;
			}
			$rows[] = array(
				'ok'        => true,
				'status'    => 'skipped',
				'surface'   => (string) ( $result['surface'] ?? $ctx->surface() ),
				'invariant' => (string) ( $skipped['name'] ?? 'skipped' ),
				'seed'      => (int) ( $result['seed'] ?? $ctx->seed() ),
				'iteration' => $ctx->iteration(),
				'data'      => array(
					'reason' => $skipped['reason'] ?? 'Skipped by surface.',
				),
			);
		}

		if ( array() === $rows ) {
			$rows[] = $this->error_row( $ctx->seed(), $ctx->surface(), $ctx->iteration(), 'empty-aggregate', 'Aggregate surface did not report any checks.' );
		}

		return $rows;
	}

	private function compact_aggregate_to_rows( array $result, FuzzContext $ctx ): array {
		$surface  = (string) ( $result['surface'] ?? $ctx->surface() );
		$seed     = (int) ( $result['seed'] ?? $ctx->seed() );
		$failures = array_values( array_filter( $result['failures'] ?? array(), 'is_array' ) );
		$rows     = array();

		if ( array() === $failures ) {
			$rows[] = array(
				'ok'        => true,
				'status'    => 'passed',
				'surface'   => $surface,
				'invariant' => $surface . '.aggregate',
				'seed'      => $seed,
				'iteration' => $ctx->iteration(),
				'data'      => array(
					'cases'        => $result['cases'] ?? ( $result['caseCount'] ?? null ),
					'checks'       => $result['checks'] ?? null,
					'assertions'   => $result['assertions'] ?? null,
					'apiCalls'     => $result['apiCalls'] ?? null,
					'failureCount' => $result['failureCount'] ?? count( $failures ),
					'coverage'     => $result['coverage'] ?? ( $result['features'] ?? array() ),
				),
			);
		} else {
			foreach ( $failures as $failure ) {
				$rows[] = array(
					'ok'        => false,
					'status'    => 'failed',
					'surface'   => $surface,
					'invariant' => (string) ( $failure['invariant'] ?? $surface . '.aggregate-failure' ),
					'seed'      => $seed,
					'iteration' => $ctx->iteration(),
					'data'      => $failure,
				);
			}
		}

		foreach ( array( 'skips', 'skipped' ) as $skip_key ) {
			foreach ( $result[ $skip_key ] ?? array() as $name => $skip ) {
				$invariant = is_array( $skip ) ? (string) ( $skip['name'] ?? $surface . '.skip' ) : ( is_string( $name ) ? $name : (string) $skip );
				$reason    = is_array( $skip ) ? ( $skip['reason'] ?? $skip ) : $skip;

				$rows[] = array(
					'ok'        => true,
					'status'    => 'skipped',
					'surface'   => $surface,
					'invariant' => $invariant,
					'seed'      => $seed,
					'iteration' => $ctx->iteration(),
					'data'      => array(
						'reason' => $reason,
					),
				);
			}
		}

		return $rows;
	}

	private function error_row( int $seed, string $surface, int $iteration, string $invariant, string $message, ?\Throwable $throwable = null ): array {
		$data = array( 'message' => $message );
		if ( null !== $throwable ) {
			$data['throwable'] = get_class( $throwable );
			$data['file']      = $throwable->getFile();
			$data['line']      = $throwable->getLine();
		}

		return array(
			'ok'        => false,
			'status'    => 'errored',
			'surface'   => $surface,
			'invariant' => $invariant,
			'seed'      => $seed,
			'iteration' => $iteration,
			'data'      => $data,
		);
	}

	private function record_row( array &$summary, array $row ): void {
		$status = $row['status'] ?? ( empty( $row['ok'] ) ? 'failed' : 'passed' );
		if ( 'skipped' === $status ) {
			$summary['counts']['skipped']++;
		} elseif ( 'errored' === $status ) {
			$summary['counts']['errored']++;
			$summary['failures'][] = $row;
		} elseif ( empty( $row['ok'] ) ) {
			$summary['counts']['failed']++;
			$summary['failures'][] = $row;
		} else {
			$summary['counts']['passed']++;
		}
	}
}
