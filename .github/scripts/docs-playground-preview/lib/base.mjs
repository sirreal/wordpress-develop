import { cp, mkdir, mkdtemp, readFile, rm, writeFile } from 'node:fs/promises';
import { existsSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import os from 'node:os';
import path from 'node:path';

import { run } from './process.mjs';
import { buildSnapshot } from './snapshot.mjs';

const SCRIPT_ROOT = path.resolve(
	path.dirname( fileURLToPath( import.meta.url ) ),
	'..'
);
const PHP_ROOT = path.join( SCRIPT_ROOT, 'php' );
const TOOLING_ROOT = path.resolve(
	SCRIPT_ROOT,
	'../../docs-playground-preview'
);
const NODE_MODULES = path.join( TOOLING_ROOT, 'node_modules' );
const COMPOSER_INSTALL = [
	'install',
	'--no-interaction',
	'--no-dev',
	'--prefer-dist',
];
// Build output and version control never belong in an installed plugin or theme.
const BUNDLE_EXCLUDES = [ '.git', '.github', 'node_modules', 'tests' ];
const PINNED_COMMIT = /^[0-9a-f]{40}$/;

/**
 * @param {string} name
 */
function executable( name ) {
	return path.join( NODE_MODULES, '.bin', name );
}

/**
 * Checks out every upstream dependency at its pinned commit, fetching only the
 * commit itself rather than the history behind it.
 *
 * @param {Record<string, any>} repositories
 * @param {string} destination
 */
async function acquireRepositories( repositories, destination ) {
	for ( const [ name, dependency ] of Object.entries( repositories ) ) {
		// The cache key covers the manifest as text, so a branch or a tag here
		// would let the base change under a key that stays the same, and a
		// value starting with a dash would reach git as an option.
		if ( ! PINNED_COMMIT.test( dependency.commit ) ) {
			throw new Error(
				`repositories.${ name }.commit must be a full commit hash.`
			);
		}
		const target = path.join( destination, name );
		const label = dependency.repository;
		await mkdir( target, { recursive: true } );
		await run( 'git', [ 'init', '--quiet' ], {
			cwd: target,
			label: `initialize ${ label }`,
		} );
		await run(
			'git',
			[
				'fetch',
				'--quiet',
				'--depth=1',
				`https://github.com/${ dependency.repository }.git`,
				dependency.commit,
			],
			{ cwd: target, label: `fetch ${ label }` }
		);
		await run(
			'git',
			[
				'-c',
				'advice.detachedHead=false',
				'checkout',
				'--quiet',
				'FETCH_HEAD',
			],
			{ cwd: target, label: `checkout ${ label }` }
		);
	}
}

/**
 * @param {string} source
 * @param {string} destination
 * @param {string[]} excludes
 */
async function copyDirectory( source, destination, excludes ) {
	const root = path.resolve( source );
	await mkdir( destination, { recursive: true } );
	await cp( root, destination, {
		recursive: true,
		filter: ( filename ) => {
			const relative = path.relative( root, filename );
			return (
				! relative ||
				! excludes.some(
					( excluded ) =>
						relative === excluded ||
						relative.startsWith( `${ excluded }/` )
				)
			);
		},
	} );
}

/**
 * Packages an installable ZIP whose entries live under `rootName`, the
 * directory name WordPress installs the plugin or theme into.
 *
 * @param {string} source
 * @param {string} output
 * @param {string} rootName
 */
async function zipDirectory( source, output, rootName ) {
	const temporary = await mkdtemp(
		path.join( os.tmpdir(), 'docs-preview-zip-' )
	);
	const target =
		rootName === '.' ? temporary : path.join( temporary, rootName );
	try {
		await copyDirectory( source, target, BUNDLE_EXCLUDES );
		await run( 'zip', [ '-rq', path.resolve( output ), '.' ], {
			cwd: temporary,
			label: `package ${ rootName }`,
		} );
	} finally {
		await rm( temporary, { recursive: true, force: true } );
	}
}

/**
 * The CJK serif faces are tens of megabytes and no Code Reference page uses
 * them; the snapshot has to stay under 100 MiB.
 *
 * @param {string} muPlugins
 */
async function prunePreviewFonts( muPlugins ) {
	await rm( path.join( muPlugins, 'global-fonts/NotoSerif' ), {
		recursive: true,
		force: true,
	} );
	const stylesheet = path.join( muPlugins, 'global-fonts/style.css' );
	const css = await readFile( stylesheet, 'utf8' );
	await writeFile(
		stylesheet,
		css
			.split( '\n' )
			.filter( ( line ) => ! line.includes( '@import "./NotoSerif/' ) )
			.join( '\n' )
	);
}

/**
 * @param {string} upstreams
 * @param {Record<string, any>} inputs
 */
function repositoryRoots( upstreams, inputs ) {
	const root = /** @param {string} name */ ( name ) =>
		path.join( upstreams, name );
	const selected = /** @param {string} name */ ( name ) =>
		path.join(
			root( name ),
			inputs.dependencies.repositories[ name ].path
		);
	return {
		phpdocParser: selected( 'phpdocParser' ),
		wporgDeveloperTheme: selected( 'wporgDeveloper' ),
		wporgParent2021: root( 'wporgParent2021' ),
		wporgParentTheme: selected( 'wporgParent2021' ),
		wporgMuPlugins: root( 'wporgMuPlugins' ),
		wporgMuPluginsFiles: selected( 'wporgMuPlugins' ),
		postsToPosts: selected( 'postsToPosts' ),
		codeSyntaxBlock: selected( 'codeSyntaxBlock' ),
	};
}

/**
 * The base holds the DevHub site with no reference content at all, so it stays
 * valid for every pull request that resolves to the same cache key.
 *
 * @param {Record<string, any>} inputs
 */
function baseBlueprint( inputs ) {
	return {
		meta: {
			title: 'WordPress Core Code Reference invariant base',
			author: 'WordPress',
			description:
				'Pinned DevHub dependencies with an empty reference index.',
		},
		preferredVersions: {
			php: inputs.dependencies.playground.phpVersion,
			wp: inputs.wordpress.version,
		},
		landingPage: '/reference/',
		login: false,
		features: { networking: false },
		extraLibraries: [ 'wp-cli' ],
		steps: [
			{
				step: 'writeFile',
				path: '/wordpress/wp-content/mu-plugins/000-docs-preview-build.php',
				data: { resource: 'bundled', path: 'php/base-policy.php' },
			},
			{
				step: 'unzip',
				zipFile: {
					resource: 'bundled',
					path: 'bundles/wporg-mu-plugins.zip',
				},
				extractToPath: '/wordpress/wp-content/mu-plugins',
			},
			...[ 'wporg-parent-2021', 'wporg-developer-2023' ].map(
				( theme ) => ( {
					step: 'installTheme',
					themeData: {
						resource: 'bundled',
						path: `bundles/${ theme }.zip`,
					},
					ifAlreadyInstalled: 'overwrite',
				} )
			),
			...[ 'code-syntax-block', 'posts-to-posts', 'phpdoc-parser' ].map(
				( plugin ) => ( {
					step: 'unzip',
					zipFile: {
						resource: 'bundled',
						path: `bundles/${ plugin }.zip`,
					},
					extractToPath: '/wordpress/wp-content/plugins',
				} )
			),
			{
				step: 'writeFile',
				path: '/tmp/docs-preview-configure-base.php',
				data: { resource: 'bundled', path: 'php/configure-base.php' },
			},
			{
				step: 'wp-cli',
				command: 'wp eval-file /tmp/docs-preview-configure-base.php',
			},
		],
	};
}

/**
 * @param {Record<string, any>} inputs
 * @param {string} playgroundCli
 */
async function buildInvariantBase( inputs, playgroundCli ) {
	const work = path.join( inputs.cacheDirectory, 'work' );
	const upstreams = path.join( work, 'upstreams' );
	const bundles = path.join( work, 'bundles' );
	await mkdir( bundles, { recursive: true } );
	await acquireRepositories( inputs.dependencies.repositories, upstreams );
	const roots = repositoryRoots( upstreams, inputs );

	const wpScriptsEnvironment = { NODE_PATH: NODE_MODULES };
	const plan = [
		{
			command: 'composer',
			args: COMPOSER_INSTALL,
			cwd: roots.phpdocParser,
			label: 'install phpdoc-parser dependencies',
		},
		{
			command: executable( 'yarn' ),
			args: [ 'install', '--frozen-lockfile', '--ignore-scripts' ],
			cwd: roots.wporgParent2021,
			label: 'install wporg-parent-2021 dependencies',
		},
		{
			command: executable( 'yarn' ),
			args: [ 'build:theme' ],
			cwd: roots.wporgParent2021,
			label: 'build wporg-parent-2021',
		},
		{
			command: executable( 'wp-scripts' ),
			args: [ 'build' ],
			cwd: roots.wporgDeveloperTheme,
			env: wpScriptsEnvironment,
			label: 'build wporg-developer-2023',
		},
		{
			command: 'npm',
			args: [ 'ci', '--ignore-scripts', '--no-audit', '--no-fund' ],
			cwd: roots.wporgMuPlugins,
			label: 'install wporg-mu-plugins dependencies',
		},
		{
			command: 'composer',
			args: COMPOSER_INSTALL,
			cwd: roots.wporgMuPlugins,
			label: 'install wporg-mu-plugins Composer dependencies',
		},
		{
			command: 'npm',
			args: [ 'run', 'build' ],
			cwd: roots.wporgMuPlugins,
			label: 'build wporg-mu-plugins',
		},
		{
			command: 'composer',
			args: [ 'config', 'allow-plugins.composer/installers', 'true' ],
			cwd: roots.postsToPosts,
			label: 'configure posts-to-posts installer',
		},
		{
			command: 'composer',
			args: COMPOSER_INSTALL,
			cwd: roots.postsToPosts,
			label: 'install posts-to-posts dependencies',
		},
		{
			command: executable( 'wp-scripts' ),
			args: [ 'build' ],
			cwd: roots.codeSyntaxBlock,
			env: wpScriptsEnvironment,
			label: 'build code-syntax-block',
		},
	];
	for ( const task of plan ) {
		await run( task.command, task.args, task );
	}
	await prunePreviewFonts( roots.wporgMuPluginsFiles );

	for ( const [ name, source, rootName ] of [
		[ 'wporg-parent-2021', roots.wporgParentTheme, 'wporg-parent-2021' ],
		[
			'wporg-developer-2023',
			roots.wporgDeveloperTheme,
			'wporg-developer-2023',
		],
		[ 'wporg-mu-plugins', roots.wporgMuPluginsFiles, '.' ],
		[ 'posts-to-posts', roots.postsToPosts, 'posts-to-posts' ],
		[ 'phpdoc-parser', roots.phpdocParser, 'phpdoc-parser' ],
		[ 'code-syntax-block', roots.codeSyntaxBlock, 'code-syntax-block' ],
	] ) {
		await zipDirectory(
			source,
			path.join( bundles, `${ name }.zip` ),
			rootName
		);
	}

	await mkdir( path.join( work, 'php' ), { recursive: true } );
	for ( const script of [ 'base-policy.php', 'configure-base.php' ] ) {
		await cp(
			path.join( PHP_ROOT, script ),
			path.join( work, 'php', script )
		);
	}
	const blueprint = path.join( work, 'base-blueprint.json' );
	await writeFile(
		blueprint,
		`${ JSON.stringify( baseBlueprint( inputs ), null, 2 ) }\n`
	);
	await buildSnapshot( inputs, playgroundCli, {
		blueprint,
		outfile: path.join( inputs.cacheDirectory, 'base.zip' ),
	} );
	// Every build runs the parser from the cache, so it is kept; the clones and
	// bundles it was built from are not worth restoring.
	await copyDirectory(
		roots.phpdocParser,
		path.join( inputs.cacheDirectory, 'parser' ),
		[ '.git' ]
	);
	await rm( work, { recursive: true, force: true } );
}

/**
 * Restores the cached base site, or builds it when the cache is cold or was
 * only partly restored.
 *
 * @param {Record<string, any>} inputs
 * @param {string} playgroundCli
 */
export async function ensureInvariantBase( inputs, playgroundCli ) {
	const snapshot = path.join( inputs.cacheDirectory, 'base.zip' );
	const parser = path.join(
		inputs.cacheDirectory,
		'parser/generate-json-manually.php'
	);
	if ( existsSync( snapshot ) && existsSync( parser ) ) {
		process.stdout.write(
			`Reusing the cached site base ${ inputs.cacheKey }.\n`
		);
		return;
	}
	await rm( inputs.cacheDirectory, { recursive: true, force: true } );
	await mkdir( inputs.cacheDirectory, { recursive: true } );
	await buildInvariantBase( inputs, playgroundCli );
}
