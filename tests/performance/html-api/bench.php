<?php
/**
 * HTML API parsing benchmark.
 *
 * Measures parsing throughput of WP_HTML_Tag_Processor and WP_HTML_Processor,
 * single-tree or A/B between two checkouts, with bootstrap confidence intervals.
 *
 * Usage: php tests/performance/html-api/bench.php [--base <checkout>] [--head <checkout>] [options]
 * See README.md for the options and how to read the output.
 *
 * @package WordPress
 * @subpackage HTML-API
 */

if ( PHP_VERSION_ID < 80100 ) {
	fwrite( STDERR, "bench.php requires PHP 8.1 or newer to run the orchestrator (workers may use any version).\n" );
	exit( 2 );
}

require_once __DIR__ . '/lib/class-benchmark-stats.php';
require_once __DIR__ . '/lib/class-benchmark-report.php';

/**
 * Prints a message to stderr and exits.
 *
 * @param string $message Message.
 * @param int    $code    Exit code.
 */
function benchmark_fail( string $message, int $code = 1 ): void {
	fwrite( STDERR, "bench.php: {$message}\n" );
	exit( $code );
}

/**
 * Parses command line options.
 *
 * Long options only, as `--name=value` or `--name value`; flags take no value.
 *
 * @param string[] $argv Arguments.
 * @return array Options.
 */
function benchmark_parse_options( array $argv ): array {
	$flags      = array( 'opcache', 'no-opcache', 'jit', 'synthetic', 'no-synthetic', 'no-corpus', 'include-optional', 'quiet', 'list', 'help' );
	$repeatable = array( 'corpus' );
	$valued     = array( 'head', 'base', 'php', 'php-args', 'parser', 'synthetic-size', 'seed', 'filter', 'samples', 'min-sample-ms', 'warmup', 'rounds', 'format', 'save' );

	$options = array( 'corpus' => array() );
	$count   = count( $argv );
	for ( $i = 1; $i < $count; $i++ ) {
		$arg = $argv[ $i ];
		if ( 0 !== strpos( $arg, '--' ) ) {
			benchmark_fail( "Unexpected argument: {$arg}" );
		}
		$name  = substr( $arg, 2 );
		$value = null;
		$equal = strpos( $name, '=' );
		if ( false !== $equal ) {
			$value = substr( $name, $equal + 1 );
			$name  = substr( $name, 0, $equal );
		}

		if ( in_array( $name, $flags, true ) ) {
			if ( null !== $value ) {
				benchmark_fail( "--{$name} takes no value" );
			}
			$options[ $name ] = true;
			continue;
		}

		if ( ! in_array( $name, $valued, true ) && ! in_array( $name, $repeatable, true ) ) {
			benchmark_fail( "Unknown option: --{$name}" );
		}

		if ( null === $value ) {
			if ( ! isset( $argv[ $i + 1 ] ) ) {
				benchmark_fail( "--{$name} requires a value" );
			}
			$value = $argv[ ++$i ];
		}

		if ( in_array( $name, $repeatable, true ) ) {
			$options[ $name ][] = $value;
		} else {
			$options[ $name ] = $value;
		}
	}

	return $options;
}

/**
 * Prints usage.
 */
function benchmark_usage(): void {
	echo <<<'USAGE'
Usage: php bench.php [options]

  --head <checkout>        Checkout to measure (default: the one containing bench.php).
  --base <checkout>        Baseline checkout; enables A/B mode.
  --php <binary>           PHP binary for the workers (default: php).
  --php-args "<args>"      Extra arguments passed to both workers.
  --opcache | --no-opcache Enable opcache in the workers (default: on when available).
  --jit                    Enable the tracing JIT (default: off).
  --parser tag|html|both   Which parser to measure (default: both).
  --corpus <dir>           Directory of *.html files; repeatable (default: corpus/real if non-empty).
  --no-corpus              Skip corpus/real; only --corpus directories given explicitly are read.
  --include-optional       Include corpus documents the manifest marks optional (the 15 MB single-page spec).
  --synthetic | --no-synthetic  Include the synthetic shapes (default: on).
  --synthetic-size <bytes> Target size of each synthetic document (default: 200000).
  --seed <int>             Seed for synthetic documents and the bootstrap (default: 1).
  --filter <substring|/regex/>  Restrict document ids.
  --samples <n>            Timed samples per document, parser and tree, per round (default: 10).
  --min-sample-ms <n>      Minimum duration of one sample (default: 25).
  --warmup <n>             Untimed parses before sampling (default: 2).
  --rounds <n>             Repeat the whole run with a fresh pair of workers each time (default: 4).
                           Each PHP process runs the same code at a slightly different speed; more
                           rounds turn that per-process offset into measured variance.
  --format table|markdown|json  Report format (default: table).
  --save <file>            Write the full JSON result regardless of --format.
  --quiet                  No progress on stderr.
  --list                   Print document ids and sizes, then exit.

USAGE;
}

/**
 * Finds the checkout root containing a path by walking up to the directory holding src/wp-includes/html-api.
 *
 * @param string $start Starting path.
 * @return string|null Checkout root.
 */
function benchmark_find_checkout( string $start ): ?string {
	$dir = realpath( $start );
	if ( false === $dir ) {
		return null;
	}
	if ( is_file( $dir ) ) {
		$dir = dirname( $dir );
	}
	while ( true ) {
		if ( is_dir( "{$dir}/src/wp-includes/html-api" ) ) {
			return $dir;
		}
		$parent = dirname( $dir );
		if ( $parent === $dir ) {
			return null;
		}
		$dir = $parent;
	}
}

/**
 * Validates a checkout path given on the command line.
 *
 * @param string $path  Path.
 * @param string $label Option name for messages.
 * @return string Resolved path.
 */
function benchmark_checkout_option( string $path, string $label ): string {
	$resolved = realpath( $path );
	if ( false === $resolved || ! is_dir( "{$resolved}/src/wp-includes/html-api" ) ) {
		benchmark_fail( "--{$label}: not a WordPress checkout (no src/wp-includes/html-api): {$path}" );
	}
	return $resolved;
}

/**
 * Whether the opcache extension is available in a PHP binary.
 *
 * @param string $php Binary.
 * @return bool
 */
function benchmark_php_has_opcache( string $php ): bool {
	$output = array();
	$status = 1;
	exec( escapeshellarg( $php ) . ' -r ' . escapeshellarg( 'echo (int) extension_loaded("Zend OPcache");' ) . ' 2>/dev/null', $output, $status );
	if ( 0 !== $status ) {
		benchmark_fail( "Could not run PHP binary: {$php}" );
	}
	return '1' === trim( implode( '', $output ) );
}

/**
 * Whether a document id passes the filter.
 *
 * A filter wrapped in slashes (`/.../flags`) is a regular expression; anything else is a substring.
 *
 * @param string      $id     Document id.
 * @param string|null $filter Filter.
 * @return bool
 */
function benchmark_filter_matches( string $id, ?string $filter ): bool {
	if ( null === $filter || '' === $filter ) {
		return true;
	}
	if ( strlen( $filter ) >= 2 && '/' === $filter[0] && preg_match( '~^/.*/[a-zA-Z]*$~s', $filter ) ) {
		$matched = @preg_match( $filter, $id );
		if ( false !== $matched ) {
			return 1 === $matched;
		}
	}
	return false !== strpos( $id, $filter );
}

/**
 * Reads the ids the corpus manifest marks optional, keyed by file name.
 *
 * The manifest sits beside the fetched directory (`corpus/manifest.json` for
 * `corpus/real`); directories without one have no optional documents.
 *
 * @param string $dir Resolved corpus directory.
 * @return array<string, true> File names to skip unless --include-optional.
 */
function benchmark_optional_ids( string $dir ): array {
	$manifest = dirname( $dir ) . '/manifest.json';
	if ( ! is_file( $manifest ) ) {
		return array();
	}
	$data = json_decode( (string) file_get_contents( $manifest ), true );
	if ( ! is_array( $data ) || ! isset( $data['documents'] ) || ! is_array( $data['documents'] ) ) {
		return array();
	}
	$optional = array();
	foreach ( $data['documents'] as $entry ) {
		if ( ! empty( $entry['optional'] ) && isset( $entry['id'] ) ) {
			$optional[ $entry['id'] . '.html' ] = true;
		}
	}
	return $optional;
}

/**
 * Collects the documents to benchmark.
 *
 * @param array $config Resolved configuration.
 * @return array<int, array{id:string, bytes:int, source:string, path:?string, html:?string}>
 */
function benchmark_collect_documents( array $config ): array {
	$documents = array();

	if ( $config['synthetic'] ) {
		$synthetic_file = __DIR__ . '/lib/class-benchmark-synthetic.php';
		if ( ! file_exists( $synthetic_file ) ) {
			benchmark_fail( 'lib/class-benchmark-synthetic.php is missing; pass --no-synthetic to run without synthetic documents.' );
		}
		require_once $synthetic_file;
		foreach ( Benchmark_Synthetic::shapes() as $shape ) {
			if ( ! benchmark_filter_matches( $shape, $config['filter'] ) ) {
				continue;
			}
			$html        = Benchmark_Synthetic::generate( $shape, $config['synthetic_size'], $config['seed'] );
			$documents[] = array(
				'id'     => $shape,
				'bytes'  => strlen( $html ),
				'source' => 'synthetic',
				'path'   => null,
				'html'   => $html,
			);
		}
	}

	foreach ( $config['corpus'] as $dir ) {
		$resolved = realpath( $dir );
		if ( false === $resolved || ! is_dir( $resolved ) ) {
			benchmark_fail( "--corpus: not a directory: {$dir}" );
		}
		$html_files = glob( "{$resolved}/*.html" );
		$htm_files  = glob( "{$resolved}/*.htm" );
		$files      = array_merge( false === $html_files ? array() : $html_files, false === $htm_files ? array() : $htm_files );
		sort( $files, SORT_STRING );
		$optional = $config['include_optional'] ? array() : benchmark_optional_ids( $resolved );
		foreach ( $files as $file ) {
			$id = basename( $file );
			if ( ! benchmark_filter_matches( $id, $config['filter'] ) ) {
				continue;
			}
			if ( isset( $optional[ $id ] ) ) {
				continue;
			}
			$documents[] = array(
				'id'     => $id,
				'bytes'  => (int) filesize( $file ),
				'source' => 'corpus',
				'path'   => $file,
				'html'   => null,
			);
		}
	}

	$seen = array();
	foreach ( $documents as $document ) {
		if ( isset( $seen[ $document['id'] ] ) ) {
			benchmark_fail( "Duplicate document id: {$document['id']}" );
		}
		$seen[ $document['id'] ] = true;
	}

	return $documents;
}

/**
 * One worker subprocess running worker.php against one checkout.
 */
class Benchmark_Worker {
	/**
	 * Tree label, 'head' or 'base'.
	 *
	 * @var string
	 */
	public $label;

	/**
	 * Checkout root.
	 *
	 * @var string
	 */
	public $checkout;

	/**
	 * Reply to the hello request.
	 *
	 * @var array
	 */
	public $hello = array();

	/**
	 * Process handle.
	 *
	 * @var resource
	 */
	private $process;

	/**
	 * Pipes: 0 stdin, 1 stdout, 2 stderr.
	 *
	 * @var resource[]
	 */
	private $pipes;

	/**
	 * Command line, for messages.
	 *
	 * @var string[]
	 */
	private $command;

	/**
	 * Starts the worker.
	 *
	 * @param string   $label    Tree label.
	 * @param string   $checkout Checkout root.
	 * @param string   $php      PHP binary.
	 * @param string[] $php_args Arguments placed before worker.php.
	 */
	public function __construct( string $label, string $checkout, string $php, array $php_args ) {
		$this->label    = $label;
		$this->checkout = $checkout;
		$this->command  = array_merge( array( $php ), $php_args, array( __DIR__ . '/worker.php', '--checkout', $checkout ) );

		$descriptors   = array(
			0 => array( 'pipe', 'r' ),
			1 => array( 'pipe', 'w' ),
			2 => array( 'pipe', 'w' ),
		);
		$this->process = proc_open( $this->command, $descriptors, $this->pipes );
		if ( ! is_resource( $this->process ) ) {
			benchmark_fail( "Could not start worker for {$label}: " . implode( ' ', $this->command ) );
		}
		stream_set_blocking( $this->pipes[2], false );

		$this->hello = $this->request( array( 'op' => 'hello' ) );
	}

	/**
	 * Sends one request and returns the reply.
	 *
	 * @param array $request Request.
	 * @return array Reply with ok=true.
	 */
	public function request( array $request ): array {
		$line = json_encode( $request, JSON_UNESCAPED_SLASHES );
		if ( false === $line ) {
			benchmark_fail( "Could not encode request for the {$this->label} worker: " . json_last_error_msg() );
		}
		fwrite( $this->pipes[0], $line . "\n" );
		fflush( $this->pipes[0] );

		$reply = fgets( $this->pipes[1] );
		$diag  = $this->drain_stderr();
		if ( false === $reply ) {
			$status = proc_get_status( $this->process );
			benchmark_fail(
				sprintf(
					"The %s worker exited (code %s) while handling %s.\nCommand: %s\n%s",
					$this->label,
					$status['running'] ? '?' : (string) $status['exitcode'],
					$request['op'],
					implode( ' ', $this->command ),
					$diag
				)
			);
		}
		if ( '' !== $diag && ! benchmark_quiet() ) {
			fwrite( STDERR, $diag );
		}

		$decoded = json_decode( $reply, true );
		if ( ! is_array( $decoded ) ) {
			benchmark_fail( "Malformed reply from the {$this->label} worker: " . trim( $reply ) );
		}
		if ( empty( $decoded['ok'] ) ) {
			benchmark_fail( "The {$this->label} worker failed {$request['op']}: " . ( $decoded['error'] ?? 'unknown error' ) );
		}
		return $decoded;
	}

	/**
	 * Reads whatever the worker wrote to stderr.
	 *
	 * @return string
	 */
	private function drain_stderr(): string {
		$out = '';
		while ( true ) {
			$chunk = fread( $this->pipes[2], 8192 );
			if ( false === $chunk || '' === $chunk ) {
				break;
			}
			$out .= $chunk;
		}
		return $out;
	}

	/**
	 * Stops the worker.
	 */
	public function quit(): void {
		if ( ! is_resource( $this->process ) ) {
			return;
		}
		@fwrite( $this->pipes[0], json_encode( array( 'op' => 'quit' ) ) . "\n" );
		@fclose( $this->pipes[0] );
		@fclose( $this->pipes[1] );
		@fclose( $this->pipes[2] );
		proc_close( $this->process );
	}
}

/**
 * Whether --quiet was given.
 *
 * @param bool|null $set Sets the value when not null.
 * @return bool
 */
function benchmark_quiet( ?bool $set = null ): bool {
	static $quiet = false;
	if ( null !== $set ) {
		$quiet = $set;
	}
	return $quiet;
}

/**
 * Prints progress to stderr unless quiet.
 *
 * @param string $message Message.
 */
function benchmark_progress( string $message ): void {
	if ( ! benchmark_quiet() ) {
		fwrite( STDERR, $message . "\n" );
	}
}

/**
 * Loads a document into a worker.
 *
 * Inline HTML is sent when it encodes as JSON; otherwise the document goes through a temporary file.
 *
 * @param Benchmark_Worker $worker   Worker.
 * @param array            $document Document.
 * @param string[]         $temp_files Temporary files created, appended to.
 */
function benchmark_load_document( Benchmark_Worker $worker, array $document, array &$temp_files ): void {
	if ( null !== $document['path'] ) {
		$reply = $worker->request(
			array(
				'op'   => 'load',
				'id'   => $document['id'],
				'path' => $document['path'],
			)
		);
	} elseif ( false !== json_encode( $document['html'] ) ) {
		$reply = $worker->request(
			array(
				'op'   => 'load',
				'id'   => $document['id'],
				'html' => $document['html'],
			)
		);
	} else {
		$temp = tempnam( sys_get_temp_dir(), 'html-api-bench-' );
		file_put_contents( $temp, $document['html'] );
		$temp_files[] = $temp;
		$reply        = $worker->request(
			array(
				'op'   => 'load',
				'id'   => $document['id'],
				'path' => $temp,
			)
		);
	}
	if ( (int) $reply['bytes'] !== $document['bytes'] ) {
		benchmark_fail( "The {$worker->label} worker loaded {$reply['bytes']} bytes for {$document['id']}, expected {$document['bytes']}." );
	}
}

/**
 * Starts the worker pair.
 *
 * Odd rounds start base first, even rounds head first, so that any effect of
 * start order cancels across rounds. The returned order is the ABBA order for
 * the first sample.
 *
 * @param array    $config      Resolved configuration.
 * @param string[] $worker_args Arguments placed before worker.php.
 * @param int      $round       Round number, from 1.
 * @return Benchmark_Worker[] Workers keyed by label, in start order.
 */
function benchmark_start_workers( array $config, array $worker_args, int $round ): array {
	$labels = array( 'head' );
	if ( null !== $config['base'] ) {
		$labels = 0 === $round % 2 ? array( 'head', 'base' ) : array( 'base', 'head' );
	}
	$workers = array();
	foreach ( $labels as $label ) {
		$workers[ $label ] = new Benchmark_Worker( $label, $config[ $label ], $config['php'], $worker_args );
	}
	return $workers;
}

/**
 * Runs one round: every (document, parser) through the given workers with ABBA interleaving.
 *
 * @param array              $config    Resolved configuration.
 * @param array              $documents Documents.
 * @param Benchmark_Worker[] $workers   Workers keyed by label.
 * @param string[]           $parsers   Parser ids.
 * @param int                $round     Round number, from 1.
 * @return array Raw rows keyed by "id\0parser": id, parser, bytes, iterations, trees => label => samples_ns, tokens, bailed, peak_bytes, peak_alloc_bytes.
 */
function benchmark_run_round( array $config, array $documents, array $workers, array $parsers, int $round ): array {
	$labels     = array_keys( $workers );
	$is_ab      = isset( $workers['base'] );
	$rows       = array();
	$temp_files = array();
	$total      = count( $documents ) * count( $parsers );
	$done       = 0;
	$prefix     = $config['rounds'] > 1 ? sprintf( '[round %d/%d] ', $round, $config['rounds'] ) : '';

	foreach ( $documents as $document ) {
		foreach ( $workers as $worker ) {
			benchmark_load_document( $worker, $document, $temp_files );
		}

		foreach ( $parsers as $parser ) {
			++$done;
			$id = $document['id'];

			// Warm up.
			if ( $config['warmup'] > 0 ) {
				foreach ( $workers as $worker ) {
					$worker->request(
						array(
							'op'         => 'run',
							'id'         => $id,
							'parser'     => $parser,
							'iterations' => $config['warmup'],
						)
					);
				}
			}

			// Calibrate: one parse per tree, iterations from the slower tree.
			$slowest_ns = 0;
			foreach ( $workers as $worker ) {
				$reply      = $worker->request(
					array(
						'op'     => 'calibrate',
						'id'     => $id,
						'parser' => $parser,
					)
				);
				$slowest_ns = max( $slowest_ns, (int) $reply['ns'] );
			}
			$iterations = max( 1, (int) ceil( ( $config['min_sample_ms'] * 1e6 ) / max( 1, $slowest_ns ) ) );

			// Sample with ABBA interleaving.
			$trees = array();
			foreach ( $labels as $label ) {
				$trees[ $label ] = array(
					'samples_ns'       => array(),
					'tokens'           => 0,
					'bailed'           => null,
					'peak_bytes'       => 0,
					'peak_alloc_bytes' => 0,
				);
			}
			for ( $sample = 1; $sample <= $config['samples']; $sample++ ) {
				$order = $labels;
				if ( 0 === $sample % 2 ) {
					$order = array_reverse( $order );
				}
				foreach ( $order as $label ) {
					$reply = $workers[ $label ]->request(
						array(
							'op'         => 'run',
							'id'         => $id,
							'parser'     => $parser,
							'iterations' => $iterations,
						)
					);

					$trees[ $label ]['samples_ns'][]     = (int) $reply['ns'] / $iterations;
					$trees[ $label ]['tokens']           = (int) $reply['tokens'];
					$trees[ $label ]['bailed']           = $reply['bailed'];
					$trees[ $label ]['peak_bytes']       = max( $trees[ $label ]['peak_bytes'], (int) $reply['peak_bytes'] );
					$trees[ $label ]['peak_alloc_bytes'] = max( $trees[ $label ]['peak_alloc_bytes'], (int) ( $reply['peak_alloc_bytes'] ?? 0 ) );
				}
			}

			$rows[ "{$id}\0{$parser}" ] = array(
				'id'         => $id,
				'parser'     => $parser,
				'bytes'      => $document['bytes'],
				'iterations' => $iterations,
				'trees'      => $trees,
			);

			$status = sprintf( '%s[%d/%d] %s %s: %d x %d parses', $prefix, $done, $total, $id, $parser, $config['samples'], $iterations );
			if ( $is_ab ) {
				$base_median = Benchmark_Stats::median( $trees['base']['samples_ns'] );
				$head_median = Benchmark_Stats::median( $trees['head']['samples_ns'] );
				$status     .= sprintf(
					', base %s ms, head %s ms, %s',
					Benchmark_Stats::format_ms( $base_median ),
					Benchmark_Stats::format_ms( $head_median ),
					Benchmark_Stats::format_pct( ( $head_median / $base_median - 1.0 ) * 100.0 )
				);
			} else {
				$stats   = Benchmark_Stats::summarize( $trees['head']['samples_ns'], $document['bytes'], $trees['head']['tokens'] );
				$status .= sprintf(
					', median %s ms, %s MB/s, CV %s',
					Benchmark_Stats::format_ms( $stats['median_ns'] ),
					Benchmark_Stats::format_rate( $stats['mb_per_s'] ),
					Benchmark_Stats::format_cv( $stats['cv'] )
				);
			}
			foreach ( $labels as $label ) {
				if ( null !== $trees[ $label ]['bailed'] ) {
					$status .= " [{$label} bailed]";
				}
			}
			benchmark_progress( $status );
		}
	}

	foreach ( $temp_files as $temp ) {
		@unlink( $temp );
	}

	return $rows;
}

/**
 * Runs every round and returns the result structure.
 *
 * @param array    $config      Resolved configuration.
 * @param array    $documents   Documents.
 * @param string[] $worker_args Arguments placed before worker.php.
 * @return array Result structure.
 */
function benchmark_run( array $config, array $documents, array $worker_args ): array {
	$is_ab      = null !== $config['base'];
	$parsers    = 'both' === $config['parser'] ? array( 'tag', 'html' ) : array( $config['parser'] );
	$trees_info = array();
	$merged     = array();

	for ( $round = 1; $round <= $config['rounds']; $round++ ) {
		$workers = benchmark_start_workers( $config, $worker_args, $round );
		if ( 1 === $round ) {
			foreach ( $workers as $label => $worker ) {
				$trees_info[ $label ] = $worker->hello;
				benchmark_progress(
					sprintf(
						'%s: %s (%s), PHP %s, opcache %s, jit %s',
						$label,
						$worker->hello['checkout'],
						$worker->hello['head'] ?? 'no git head',
						$worker->hello['php'],
						$worker->hello['opcache'] ? 'on' : 'off',
						$worker->hello['jit'] ?? 'off'
					)
				);
			}
		}

		$rows = benchmark_run_round( $config, $documents, $workers, $parsers, $round );

		foreach ( $workers as $worker ) {
			$worker->quit();
		}

		foreach ( $rows as $key => $row ) {
			if ( ! isset( $merged[ $key ] ) ) {
				$merged[ $key ] = $row;
				foreach ( $row['trees'] as $label => $tree ) {
					$merged[ $key ]['trees'][ $label ]['rounds'] = array( $tree['samples_ns'] );
				}
				$merged[ $key ]['iterations'] = array( $row['iterations'] );
				continue;
			}
			$merged[ $key ]['iterations'][] = $row['iterations'];
			foreach ( $row['trees'] as $label => $tree ) {
				$target                     = &$merged[ $key ]['trees'][ $label ];
				$target['rounds'][]         = $tree['samples_ns'];
				$target['samples_ns']       = array_merge( $target['samples_ns'], $tree['samples_ns'] );
				$target['tokens']           = $tree['tokens'];
				$target['bailed']           = $target['bailed'] ?? $tree['bailed'];
				$target['peak_bytes']       = max( $target['peak_bytes'], $tree['peak_bytes'] );
				$target['peak_alloc_bytes'] = max( $target['peak_alloc_bytes'], $tree['peak_alloc_bytes'] );
				unset( $target );
			}
		}
	}

	$labels  = array_keys( $trees_info );
	$results = array();
	foreach ( $merged as $row ) {
		foreach ( $labels as $label ) {
			$tree          = &$row['trees'][ $label ];
			$tree['stats'] = Benchmark_Stats::summarize( $tree['samples_ns'], $row['bytes'], $tree['tokens'] );
			if ( count( $tree['rounds'] ) > 1 ) {
				// With several rounds the A/B ratio is the geometric mean of per-round
				// ratios of medians; show the mean of the per-round medians beside it.
				$tree['stats']['round_mean_median_ns'] = array_sum( array_map( array( 'Benchmark_Stats', 'median' ), $tree['rounds'] ) ) / count( $tree['rounds'] );
			}
			unset( $tree );
		}
		$row['comparison'] = $is_ab
			? Benchmark_Stats::compare( $row['trees']['head']['rounds'], $row['trees']['base']['rounds'], $config['seed'] )
			: null;
		$results[]         = $row;
	}

	return array(
		'tool'      => 'html-api-benchmark',
		'version'   => 1,
		'generated' => gmdate( 'Y-m-d\TH:i:s\Z' ),
		'mode'      => $is_ab ? 'ab' : 'single',
		'config'    => $config,
		'trees'     => $trees_info,
		'documents' => array_map(
			static function ( $document ) {
				unset( $document['html'] );
				return $document;
			},
			$documents
		),
		'results'   => $results,
		'summary'   => benchmark_summarize( $results, $labels, $is_ab, $config['seed'] ),
		'notes'     => array(),
	);
}

/**
 * Computes the per-parser summary: geomean ratio with CI, throughput per tree, bailed list.
 *
 * @param array    $results Result rows.
 * @param string[] $labels  Tree labels.
 * @param bool     $is_ab   Whether this is an A/B result.
 * @param int      $seed    Bootstrap seed.
 * @return array Summary keyed by parser.
 */
function benchmark_summarize( array $results, array $labels, bool $is_ab, int $seed ): array {
	$summary = array();
	foreach ( array( 'tag', 'html' ) as $parser ) {
		$rows = array_filter(
			$results,
			static function ( $row ) use ( $parser ) {
				return $row['parser'] === $parser;
			}
		);
		if ( empty( $rows ) ) {
			continue;
		}

		$bailed    = array();
		$completed = array();
		foreach ( $rows as $row ) {
			$row_bailed = false;
			foreach ( $labels as $label ) {
				if ( null !== $row['trees'][ $label ]['bailed'] ) {
					$row_bailed = true;
					$bailed[]   = array(
						'id'     => $row['id'],
						'tree'   => $label,
						'reason' => $row['trees'][ $label ]['bailed'],
					);
				}
			}
			if ( ! $row_bailed ) {
				$completed[] = $row;
			}
		}

		$throughput = array();
		foreach ( $labels as $label ) {
			$bytes = 0;
			$ns    = 0.0;
			foreach ( $completed as $row ) {
				$bytes += $row['bytes'];
				$ns    += $row['trees'][ $label ]['stats']['median_ns'];
			}
			$throughput[ $label ] = $ns > 0 ? ( $bytes * 1000.0 ) / $ns : 0.0;
		}

		$overall = null;
		if ( $is_ab ) {
			$pairs = array();
			foreach ( $completed as $row ) {
				$pairs[] = array(
					'head' => $row['trees']['head']['rounds'],
					'base' => $row['trees']['base']['rounds'],
				);
			}
			$overall = Benchmark_Stats::geomean_ratio( $pairs, $seed );
		}

		$summary[ $parser ] = array(
			'overall'    => $overall,
			'throughput' => $throughput,
			'bailed'     => $bailed,
			'documents'  => count( $rows ),
			'completed'  => count( $completed ),
		);
	}
	return $summary;
}

// Main.

$options = benchmark_parse_options( $argv );
if ( isset( $options['help'] ) ) {
	benchmark_usage();
	exit( 0 );
}
benchmark_quiet( isset( $options['quiet'] ) );

$head = isset( $options['head'] ) ? benchmark_checkout_option( $options['head'], 'head' ) : benchmark_find_checkout( __DIR__ );
if ( null === $head ) {
	benchmark_fail( 'Could not find the checkout containing bench.php; pass --head <checkout>.' );
}
$base = isset( $options['base'] ) ? benchmark_checkout_option( $options['base'], 'base' ) : null;

$php         = $options['php'] ?? 'php';
$has_opcache = benchmark_php_has_opcache( $php );
$opcache     = $has_opcache;
if ( isset( $options['no-opcache'] ) ) {
	$opcache = false;
}
if ( isset( $options['opcache'] ) ) {
	if ( ! $has_opcache ) {
		benchmark_fail( "--opcache: the opcache extension is not available in {$php}" );
	}
	$opcache = true;
}
$jit = isset( $options['jit'] );
if ( $jit && ! $opcache ) {
	benchmark_fail( '--jit requires opcache; drop --no-opcache or check that the extension is available.' );
}

$parser = $options['parser'] ?? 'both';
if ( ! in_array( $parser, array( 'tag', 'html', 'both' ), true ) ) {
	benchmark_fail( '--parser must be tag, html, or both' );
}
$format = $options['format'] ?? 'table';
if ( ! in_array( $format, array( 'table', 'markdown', 'json' ), true ) ) {
	benchmark_fail( '--format must be table, markdown, or json' );
}

$synthetic = ! isset( $options['no-synthetic'] );
$corpus    = $options['corpus'];
if ( empty( $corpus ) && ! isset( $options['no-corpus'] ) ) {
	$default_corpus = __DIR__ . '/corpus/real';
	if ( is_dir( $default_corpus ) && ( glob( "{$default_corpus}/*.html" ) || glob( "{$default_corpus}/*.htm" ) ) ) {
		$corpus[] = $default_corpus;
	}
}

$config = array(
	'php'              => $php,
	'php_args'         => $options['php-args'] ?? '',
	'opcache'          => $opcache,
	'jit'              => $jit,
	'parser'           => $parser,
	'corpus'           => $corpus,
	'synthetic'        => $synthetic,
	'synthetic_size'   => (int) ( $options['synthetic-size'] ?? 200000 ),
	'seed'             => (int) ( $options['seed'] ?? 1 ),
	'filter'           => $options['filter'] ?? null,
	'include_optional' => isset( $options['include-optional'] ),
	'samples'          => (int) ( $options['samples'] ?? 10 ),
	'min_sample_ms'    => (int) ( $options['min-sample-ms'] ?? 25 ),
	'warmup'           => (int) ( $options['warmup'] ?? 2 ),
	'rounds'           => (int) ( $options['rounds'] ?? 4 ),
	'format'           => $format,
	'head'             => $head,
	'base'             => $base,
);
if ( $config['samples'] < 2 ) {
	benchmark_fail( '--samples must be at least 2' );
}
if ( $config['rounds'] < 1 ) {
	benchmark_fail( '--rounds must be at least 1' );
}

$documents = benchmark_collect_documents( $config );
if ( empty( $documents ) ) {
	benchmark_fail( 'No documents: fetch the corpus, pass --corpus <dir>, or drop --no-synthetic.' );
}

if ( isset( $options['list'] ) ) {
	foreach ( $documents as $document ) {
		printf( "%-40s %10s bytes  %s\n", $document['id'], Benchmark_Stats::format_int( $document['bytes'] ), $document['source'] );
	}
	printf( "%d documents\n", count( $documents ) );
	exit( 0 );
}

$worker_args = array( '-d', 'display_errors=stderr', '-d', 'memory_limit=-1' );
if ( $opcache ) {
	$worker_args[] = '-d';
	$worker_args[] = 'opcache.enable_cli=1';
	if ( $jit ) {
		array_push( $worker_args, '-d', 'opcache.jit_buffer_size=64M', '-d', 'opcache.jit=tracing' );
	}
} else {
	$worker_args[] = '-d';
	$worker_args[] = 'opcache.enable_cli=0';
}
if ( '' !== $config['php_args'] ) {
	$worker_args = array_merge( $worker_args, preg_split( '/\s+/', trim( $config['php_args'] ) ) );
}

$result = benchmark_run( $config, $documents, $worker_args );

if ( isset( $options['save'] ) ) {
	$json = json_encode( $result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
	if ( false === $json || false === file_put_contents( $options['save'], $json . "\n" ) ) {
		benchmark_fail( "Could not write {$options['save']}" );
	}
	benchmark_progress( "Saved {$options['save']}" );
}

echo Benchmark_Report::render( $result, $format );
