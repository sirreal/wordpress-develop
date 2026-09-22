#!/usr/bin/env node
'use strict';

const crypto = require( 'node:crypto' );
const fs = require( 'node:fs' );
const net = require( 'node:net' );
const os = require( 'node:os' );
const path = require( 'node:path' );
const { once } = require( 'node:events' );
const { spawn, spawnSync } = require( 'node:child_process' );

const SCRIPT_DIR = __dirname;
const VERSION_FILE = path.join( SCRIPT_DIR, 'VERSION' );
const VERSION_FILE_CONTENTS = fs.readFileSync( VERSION_FILE, 'utf8' );
if ( ! /^[0-9]+(?:\.[0-9]+){3}\n$/.test( VERSION_FILE_CONTENTS ) ) {
	throw new Error( 'VERSION must contain exactly one canonical four-component version and a final newline.' );
}
const PINNED_CHROME_VERSION = VERSION_FILE_CONTENTS.slice( 0, -1 );
const CONTEXT_FILE = path.join( SCRIPT_DIR, '..', 'fragment-contexts.json' );
const CONTEXT_FILE_CONTENTS = fs.readFileSync( CONTEXT_FILE );
const CONTEXT_FILE_SHA256 = crypto.createHash( 'sha256' ).update( CONTEXT_FILE_CONTENTS ).digest( 'hex' );
const CONTEXTS = Object.freeze( JSON.parse( CONTEXT_FILE_CONTENTS.toString( 'utf8' ) ) );
const CONTEXT_SET = new Set( CONTEXTS );
const HTML_NS = 'http://www.w3.org/1999/xhtml';
const SVG_NS = 'http://www.w3.org/2000/svg';
const MATH_NS = 'http://www.w3.org/1998/Math/MathML';
const MAX_INPUT_BYTES = 2 * 1024 * 1024;
const MAX_NODES = 100000;
const MAX_DEPTH = 1024;
const MAX_TREE_BYTES = 16 * 1024 * 1024;
const MAX_REQUEST_FRAME_BYTES = 4 * 1024 * 1024;
const MAX_RESPONSE_FRAME_BYTES = 24 * 1024 * 1024;
const MAX_CDP_FRAME_BYTES = 32 * 1024 * 1024;
const MAX_SOCKET_CLIENTS = 8;
const MAX_REQUEST_ID_BYTES = 4096;
const SOCKET_FRAME_TIMEOUT_MS = 10000;
const SOCKET_WRITE_TIMEOUT_MS = 10000;
const CHROME_STDERR_BYTES = 16384;
const INTERNAL_SUPERVISOR_ENV = 'HTML_API_FUZZ_CHROME_INTERNAL_SUPERVISOR';
const TEST_ALLOW_INTERNAL_COMMANDS_ENV = 'HTML_API_FUZZ_CHROME_TEST_ALLOW_INTERNAL_COMMANDS';
const TERMINAL_OUTPUT_STREAMS = new WeakSet();
let forceProcessExitAfterCleanup = false;
const JSON_FRAME_DECODER = new TextDecoder( 'utf-8', { fatal: true } );

if (
	! Array.isArray( CONTEXTS ) ||
	17 !== CONTEXTS.length ||
	17 !== CONTEXT_SET.size ||
	CONTEXTS.some( ( value ) => 'string' !== typeof value || ! /^[a-z]+$/.test( value ) )
) {
	throw new Error( 'fragment-contexts.json must contain 17 unique lowercase context names.' );
}

function delay( milliseconds ) {
	return new Promise( ( resolve ) => setTimeout( resolve, milliseconds ) );
}

function appendByteTail( current, chunk, maximumBytes ) {
	const combined = Buffer.concat( [ current, chunk ] );
	return combined.length <= maximumBytes ? combined : combined.subarray( combined.length - maximumBytes );
}

function decodeBoundedUtf8Tail( buffer, maximumBytes ) {
	let decoded = buffer.toString( 'utf8' );
	while ( Buffer.byteLength( decoded, 'utf8' ) > maximumBytes ) {
		decoded = decoded.slice( 1 );
	}
	return decoded;
}

function transportError( message, cause ) {
	const error = new Error( message, cause ? { cause } : undefined );
	error.transportFailure = true;
	return error;
}

function sessionDeathError( message, cause ) {
	const error = transportError( message, cause );
	error.recoverableSessionDeath = true;
	return error;
}

function cdpTimeoutError( method, kind = 'command' ) {
	const error = new Error( `CDP ${ kind } ${ method } timed out.` );
	error.failureClass = 'Runtime.evaluate' === method
		? 'oracle-evaluation-timeout'
		: 'oracle-infrastructure-timeout';
	error.invalidateSession = true;
	return error;
}

function cdpProtocolError( message, cause ) {
	const error = transportError( message, cause );
	error.failureClass = 'oracle-infrastructure-failure';
	error.invalidateSession = true;
	return error;
}

function markTransportError( error ) {
	if ( error && 'object' === typeof error ) {
		error.transportFailure = true;
		return error;
	}
	return transportError( String( error ) );
}

function isRecoverableSessionDeath( error ) {
	return true === error?.recoverableSessionDeath;
}

function isValidRequestId( id ) {
	return ( 'string' === typeof id && Buffer.byteLength( id, 'utf8' ) <= MAX_REQUEST_ID_BYTES ) ||
		( 'number' === typeof id && Number.isSafeInteger( id ) );
}

function hasExactOwnKeys( value, expected ) {
	if ( null === value || 'object' !== typeof value || Array.isArray( value ) ) {
		return false;
	}
	const actual = Object.keys( value ).sort();
	const wanted = [ ...expected ].sort();
	return actual.length === wanted.length && actual.every( ( key, index ) => key === wanted[ index ] );
}

function platformConfiguration() {
	if ( 'darwin' === process.platform && 'arm64' === process.arch ) {
		return {
			platform: 'mac-arm64',
			archiveDirectory: 'chrome-mac-arm64',
			executableRelative: 'Google Chrome for Testing.app/Contents/MacOS/Google Chrome for Testing',
		};
	}
	if ( 'darwin' === process.platform && 'x64' === process.arch ) {
		return {
			platform: 'mac-x64',
			archiveDirectory: 'chrome-mac-x64',
			executableRelative: 'Google Chrome for Testing.app/Contents/MacOS/Google Chrome for Testing',
		};
	}
	if ( 'linux' === process.platform && 'x64' === process.arch ) {
		return {
			platform: 'linux64',
			archiveDirectory: 'chrome-linux64',
			executableRelative: 'chrome',
		};
	}
	throw new Error( 'Chrome for Testing is unsupported on ' + process.platform + '/' + process.arch + '.' );
}

function manifestDigest( filename, key ) {
	const lines = fs.readFileSync( filename, 'utf8' ).split( /\r?\n/ );
	const matches = [];
	for ( const line of lines ) {
		const trimmed = line.trim();
		if ( '' === trimmed || trimmed.startsWith( '#' ) ) {
			continue;
		}
		const fields = trimmed.split( /\s+/ );
		if ( key === fields[ 1 ] ) {
			matches.push( fields );
		}
	}
	if (
		1 !== matches.length ||
		2 !== matches[ 0 ].length ||
		! /^[0-9a-f]{64}$/.test( matches[ 0 ][ 0 ] )
	) {
		throw new Error( 'Expected one valid checksum for ' + key + ' in ' + filename + '.' );
	}
	return matches[ 0 ][ 0 ];
}

async function hashFile( filename ) {
	const hash = crypto.createHash( 'sha256' );
	const stream = fs.createReadStream( filename );
	stream.on( 'data', ( chunk ) => hash.update( chunk ) );
	await once( stream, 'close' );
	return hash.digest( 'hex' );
}

function hashFileSync( filename ) {
	const hash = crypto.createHash( 'sha256' );
	const descriptor = fs.openSync( filename, 'r' );
	const buffer = Buffer.allocUnsafe( 1024 * 1024 );
	try {
		for ( ;; ) {
			const bytes = fs.readSync( descriptor, buffer, 0, buffer.length, null );
			if ( 0 === bytes ) {
				break;
			}
			hash.update( buffer.subarray( 0, bytes ) );
		}
	} finally {
		fs.closeSync( descriptor );
	}
	return hash.digest( 'hex' );
}

function fileIdentitySnapshot( filename ) {
	const realpath = fs.realpathSync( filename );
	const stat = fs.statSync( realpath, { bigint: true } );
	if ( ! stat.isFile() ) {
		throw new Error( 'Authenticated path is not a regular file: ' + filename + '.' );
	}
	return {
		realpath,
		device: stat.dev.toString(),
		inode: stat.ino.toString(),
		size: stat.size.toString(),
		mode: stat.mode.toString(),
		links: stat.nlink.toString(),
		modifiedNanoseconds: stat.mtimeNs.toString(),
		changedNanoseconds: stat.ctimeNs.toString(),
	};
}

function inputFileChangedError( message ) {
	const error = new Error( message );
	error.failureClass = 'input-file-changed';
	return error;
}

function inputByteLimitError() {
	const error = new Error( 'Input file must be regular and no larger than 2 MiB.' );
	error.failureClass = 'input-byte-limit-exceeded';
	return error;
}

function sameInputFileStat( left, right ) {
	return left.dev === right.dev && left.ino === right.ino && left.size === right.size &&
		left.mtimeNs === right.mtimeNs && left.ctimeNs === right.ctimeNs;
}

async function readBoundedRegularFile( filename, maximumBytes ) {
	const descriptor = fs.openSync(
		filename,
		fs.constants.O_RDONLY | fs.constants.O_NONBLOCK
	);
	try {
		const before = fs.fstatSync( descriptor, { bigint: true } );
		if ( ! before.isFile() || before.size > BigInt( maximumBytes ) ) {
			throw inputByteLimitError();
		}
		const pauseFile = process.env.HTML_API_FUZZ_CHROME_TEST_PAUSE_AFTER_INPUT_OPEN;
		if ( pauseFile ) {
			fs.writeFileSync(
				pauseFile,
				JSON.stringify( { pid: process.pid, input: filename } ) + '\n',
				{ flag: 'wx', mode: 0o600 }
			);
			while ( fs.existsSync( pauseFile ) ) {
				await delay( 10 );
			}
		}
		const chunks = [];
		let bytes = 0;
		while ( bytes <= maximumBytes ) {
			const buffer = Buffer.allocUnsafe( Math.min( 65536, maximumBytes + 1 - bytes ) );
			const count = fs.readSync( descriptor, buffer, 0, buffer.length, null );
			if ( 0 === count ) {
				break;
			}
			chunks.push( buffer.subarray( 0, count ) );
			bytes += count;
		}
		if ( bytes > maximumBytes ) {
			throw inputByteLimitError();
		}
		const after = fs.fstatSync( descriptor, { bigint: true } );
		let current;
		try {
			current = fs.statSync( filename, { bigint: true } );
		} catch ( error ) {
			throw inputFileChangedError( 'Input path disappeared during its bounded read.' );
		}
		if ( ! sameInputFileStat( before, after ) || ! sameInputFileStat( after, current ) ) {
			throw inputFileChangedError( 'Input file changed during its bounded read.' );
		}
		return Buffer.concat( chunks, bytes );
	} finally {
		fs.closeSync( descriptor );
	}
}

function sameFileIdentitySnapshot( left, right ) {
	return JSON.stringify( left ) === JSON.stringify( right );
}

function sameMarkerSnapshot( left, right ) {
	return ( null === left || null === right )
		? left === right
		: left.contents === right.contents && sameFileIdentitySnapshot( left.identity, right.identity );
}

function readProcessTable( timeoutMilliseconds = 1000 ) {
	const timeout = Math.max( 1, Math.min( 1000, Math.floor( timeoutMilliseconds ) ) );
	const checked = spawnSync(
		'ps',
		[ '-axww', '-o', 'pid=,ppid=,lstart=,command=' ],
		{
			encoding: 'utf8',
			timeout,
			maxBuffer: 4 * 1024 * 1024,
			env: { ...process.env, LC_ALL: 'C' },
		}
	);
	if ( checked.error || 0 !== checked.status ) {
		throw new Error( 'Could not inspect the process table.' );
	}
	const records = [];
	for ( const line of checked.stdout.split( '\n' ) ) {
		const match = line.match( /^\s*(\d+)\s+(\d+)\s+(.{24})\s+(.*)$/ );
		if ( ! match ) {
			continue;
		}
		records.push( {
			pid: Number.parseInt( match[ 1 ], 10 ),
			ppid: Number.parseInt( match[ 2 ], 10 ),
			start: match[ 3 ],
			command: match[ 4 ],
		} );
	}
	return records;
}

function linuxStartTicks( pid ) {
	if ( 'linux' !== process.platform ) {
		return null;
	}
	try {
		const stat = fs.readFileSync( '/proc/' + pid + '/stat', 'utf8' );
		const close = stat.lastIndexOf( ')' );
		if ( close < 0 ) {
			return null;
		}
		const fields = stat.slice( close + 2 ).trim().split( /\s+/ );
		return fields[ 19 ] || null;
	} catch ( _error ) {
		return null;
	}
}

function processIdentity( record ) {
	const ticks = linuxStartTicks( record.pid );
	return {
		pid: record.pid,
		ppid: record.ppid,
		birth: null === ticks ? record.start : 'linux:' + ticks,
		command: record.command,
	};
}

function captureProcessTree( rootPid, profilePath, ownershipToken, table = readProcessTable() ) {
	const byPid = new Map( table.map( ( record ) => [ record.pid, record ] ) );
	const root = byPid.get( rootPid );
	if ( ! root ) {
		return [];
	}
	if ( ! root.command.includes( '--user-data-dir=' + profilePath ) || ! root.command.includes( ownershipToken ) ) {
		throw new Error( 'Chrome process command does not carry its unique ownership identity.' );
	}
	const children = new Map();
	for ( const record of table ) {
		const list = children.get( record.ppid ) || [];
		list.push( record.pid );
		children.set( record.ppid, list );
	}
	const ordered = [];
	const queue = [ rootPid ];
	while ( queue.length ) {
		const pid = queue.shift();
		const record = byPid.get( pid );
		if ( record ) {
			ordered.push( processIdentity( record ) );
			queue.push( ...( children.get( pid ) || [] ) );
		}
	}
	return ordered;
}

function scanOwnedChromeTree( profilePath, ownershipToken, table = readProcessTable() ) {
	const root = table.find(
		( record ) =>
			record.command.includes( '--user-data-dir=' + profilePath ) &&
			record.command.includes( ownershipToken ) &&
			! record.command.includes( '--internal-supervisor' )
	);
	return root ? captureProcessTree( root.pid, profilePath, ownershipToken, table ) : [];
}

function currentIdentityForPid( pid ) {
	const record = readProcessTable().find( ( item ) => item.pid === pid );
	return record ? processIdentity( record ) : null;
}

function sameProcessIdentity( expected, actual ) {
	return Boolean(
		actual &&
		expected.pid === actual.pid &&
		expected.birth === actual.birth &&
		expected.command === actual.command
	);
}

function mergeProcessSnapshots( snapshots ) {
	const merged = new Map();
	for ( const snapshot of snapshots ) {
		for ( const record of snapshot || [] ) {
			merged.set( record.pid + ':' + record.birth, record );
		}
	}
	return [ ...merged.values() ];
}

function retainProcessSnapshot( state, snapshot ) {
	state.processSnapshots.push( snapshot );
	if ( state.processSnapshots.length > 8 ) {
		state.processSnapshots.splice( 0, state.processSnapshots.length - 8 );
	}
	return state.processSnapshots.slice();
}

function isValidProcessSnapshot( snapshot ) {
	return Array.isArray( snapshot ) && snapshot.length > 0 && snapshot.length <= 4096 && snapshot.every( ( record ) => (
		hasExactOwnKeys( record, [ 'pid', 'ppid', 'birth', 'command' ] ) &&
		Number.isSafeInteger( record.pid ) && record.pid >= 2 &&
		Number.isSafeInteger( record.ppid ) && record.ppid >= 0 &&
		'string' === typeof record.birth && record.birth.length > 0 && record.birth.length <= 256 &&
		'string' === typeof record.command && Buffer.byteLength( record.command, 'utf8' ) <= 65536
	) );
}

async function reapAuthenticatedTree( snapshots, profilePath, ownershipToken, options = {} ) {
	const tableReader = options.tableReader || readProcessTable;
	const signalProcess = options.signalProcess || ( ( pid, signal ) => process.kill( pid, signal ) );
	const totalDeadline = Date.now() + ( options.totalTimeoutMs || 6000 );
	const termDeadline = Math.min( totalDeadline, Date.now() + ( options.termTimeoutMs || 2000 ) );
	const pollMilliseconds = options.pollMilliseconds || 50;
	let records = mergeProcessSnapshots( snapshots );
	const inspect = async () => {
		const remaining = totalDeadline - Date.now();
		if ( remaining <= 0 ) {
			throw new Error( 'Authenticated Chrome cleanup exceeded its absolute deadline.' );
		}
		const table = await new Promise( ( resolve, reject ) => {
			const timer = setTimeout(
				() => reject( new Error( 'Process-table inspection exceeded the Chrome cleanup deadline.' ) ),
				remaining
			);
			timer.unref();
			Promise.resolve().then( () => tableReader( remaining ) ).then(
				( value ) => {
					clearTimeout( timer );
					resolve( value );
				},
				( error ) => {
					clearTimeout( timer );
					reject( error );
				}
			);
		} );
		if ( Date.now() >= totalDeadline ) {
			throw new Error( 'Process-table inspection exceeded the Chrome cleanup deadline.' );
		}
		const owned = scanOwnedChromeTree( profilePath, ownershipToken, table );
		records = mergeProcessSnapshots( [ records, owned ] );
		const rawByPid = new Map( table.map( ( record ) => [ record.pid, record ] ) );
		const surviving = [];
		for ( const expected of records ) {
			if ( Date.now() >= totalDeadline ) {
				throw new Error( 'Process identity inspection exceeded the Chrome cleanup deadline.' );
			}
			const raw = rawByPid.get( expected.pid );
			if ( raw && sameProcessIdentity( expected, processIdentity( raw ) ) ) {
				surviving.push( expected );
			}
		}
		if ( Date.now() >= totalDeadline ) {
			throw new Error( 'Process identity inspection exceeded the Chrome cleanup deadline.' );
		}
		return { owned, surviving };
	};
	const signalMatching = ( inspection, signal ) => {
		for ( const record of [ ...inspection.surviving ].reverse() ) {
			try {
				signalProcess( record.pid, signal );
			} catch ( error ) {
				if ( 'ESRCH' !== error.code ) {
					throw error;
				}
			}
		}
	};
	let inspection = await inspect();
	if ( 0 === inspection.surviving.length && 0 === inspection.owned.length ) {
		return;
	}
	signalMatching( inspection, 'SIGTERM' );
	while ( Date.now() < termDeadline ) {
		await delay( Math.min( pollMilliseconds, Math.max( 1, termDeadline - Date.now() ) ) );
		inspection = await inspect();
		if ( 0 === inspection.surviving.length && 0 === inspection.owned.length ) {
			return;
		}
	}
	inspection = await inspect();
	signalMatching( inspection, 'SIGKILL' );
	while ( Date.now() < totalDeadline ) {
		await delay( Math.min( pollMilliseconds, Math.max( 1, totalDeadline - Date.now() ) ) );
		inspection = await inspect();
		if ( 0 === inspection.surviving.length && 0 === inspection.owned.length ) {
			return;
		}
	}
	throw new Error( 'Authenticated Chrome process tree survived the absolute SIGKILL deadline.' );
}

function removeRuntimeResources( runtimeRoot, socketPath ) {
	if ( socketPath ) {
		try {
			fs.unlinkSync( socketPath );
		} catch ( error ) {
			if ( 'ENOENT' !== error.code ) {
				throw error;
			}
		}
	}
	if ( runtimeRoot ) {
		fs.rmSync( runtimeRoot, { recursive: true, force: true } );
	}
}

async function reapAndRemoveRuntimeResources(
	snapshots,
	profilePath,
	ownershipToken,
	runtimeRoot,
	socketPath,
	reapOptions = {}
) {
	if ( profilePath && ownershipToken ) {
		await reapAuthenticatedTree( snapshots, profilePath, ownershipToken, reapOptions );
	}
	removeRuntimeResources( runtimeRoot, socketPath );
}

function parseInternalSupervisorArgs( argv ) {
	if ( '--internal-supervisor' !== argv[ 2 ] ) {
		return null;
	}
	const separator = argv.indexOf( '--' );
	const expectedNames = [
		'--token',
		'--chrome-executable',
		'--expected-sha256',
		'--profile',
		'--runtime-root',
		'--socket',
	];
	if ( 3 + expectedNames.length * 2 !== separator || separator >= argv.length - 1 ) {
		throw new Error( 'Malformed internal supervisor arguments.' );
	}
	const options = {};
	for ( let index = 0; index < expectedNames.length; index++ ) {
		const nameIndex = 3 + index * 2;
		if ( expectedNames[ index ] !== argv[ nameIndex ] || nameIndex + 1 >= separator ) {
			throw new Error( 'Malformed internal supervisor arguments.' );
		}
		options[ expectedNames[ index ].slice( 2 ) ] = argv[ nameIndex + 1 ];
	}
	if (
		! /^[0-9a-f]{32}$/.test( options.token ) ||
		process.env[ INTERNAL_SUPERVISOR_ENV ] !== options.token ||
		! /^[0-9a-f]{64}$/.test( options[ 'expected-sha256' ] ) ||
		0 === argv.slice( separator + 1 ).length
	) {
		throw new Error( 'Unauthenticated internal supervisor invocation.' );
	}
	options.chromeArguments = argv.slice( separator + 1 );
	return options;
}

function createSupervisorEmitter( output ) {
	let backpressured = false;
	output.on( 'drain', () => {
		backpressured = false;
	} );
	return {
		emit( event, lossy = false ) {
			if ( output.destroyed || ( lossy && backpressured ) ) {
				return false;
			}
			const frame = Buffer.from( JSON.stringify( event ) + '\n', 'utf8' );
			if ( frame.length > 1024 * 1024 ) {
				if ( lossy ) {
					return false;
				}
				throw new Error( 'Critical Chrome supervisor event exceeded 1 MiB.' );
			}
			if ( ! output.write( frame ) ) {
				backpressured = true;
			}
			return true;
		},
		isBackpressured() {
			return backpressured;
		},
	};
}

async function runInternalSupervisor( options ) {
	const executable = path.resolve( options[ 'chrome-executable' ] );
	const profilePath = path.resolve( options.profile );
	const runtimeRoot = path.resolve( options[ 'runtime-root' ] );
	const socketPath = options.socket ? path.resolve( options.socket ) : '';
	const snapshots = [];
	let chrome = null;
	let chromeCarriesOwnershipToken = false;
	let cleaning = false;
	let heartbeat = null;
	let stderrTailBuffer = Buffer.alloc( 0 );
	let controlledShutdown = false;
	let resolveDone;
	const done = new Promise( ( resolve ) => {
		resolveDone = resolve;
	} );
	process.stdout.on( 'error', () => {} );
	process.stderr.on( 'error', () => {} );
	const emitter = createSupervisorEmitter( process.stdout );
	const emit = ( event ) => emitter.emit( event, false );
	const cleanup = async ( reason ) => {
		if ( cleaning ) {
			return;
		}
		cleaning = true;
		clearInterval( heartbeat );
		const errors = [];
		if ( chrome?.pid ) {
			try {
				if ( chromeCarriesOwnershipToken ) {
					snapshots.push( captureProcessTree( chrome.pid, profilePath, options.token ) );
				}
			} catch ( _error ) {}
		}
		try {
			await reapAndRemoveRuntimeResources(
				snapshots,
				profilePath,
				options.token,
				runtimeRoot,
				controlledShutdown ? '' : socketPath
			);
		} catch ( error ) {
			errors.push( error );
		}
		try {
			emit( { event: 'cleaned', reason } );
		} catch ( error ) {
			errors.push( error );
		}
		if ( 0 === errors.length ) {
			process.exitCode = 0;
		} else {
			process.stderr.write(
				'Chrome supervisor cleanup failed: ' +
				errors.map( ( error ) => error.stack || error.message || String( error ) ).join( '\n' ) +
				'\n'
			);
			process.exitCode = 1;
		}
		resolveDone();
	};

	process.once( 'SIGINT', () => cleanup( 'signal' ) );
	process.once( 'SIGTERM', () => cleanup( 'signal' ) );
	let controlBuffer = '';
	process.stdin.setEncoding( 'utf8' );
	process.stdin.on( 'data', ( chunk ) => {
		controlBuffer = ( controlBuffer + chunk ).slice( -64 );
		if ( controlBuffer.includes( 'controlled-shutdown\n' ) ) {
			controlledShutdown = true;
		}
	} );
	process.stdin.resume();
	process.stdin.once( 'end', () => cleanup( 'owner-eof' ) );
	process.stdin.once( 'close', () => cleanup( 'owner-close' ) );
	process.stdin.once( 'error', () => cleanup( 'owner-stdin-error' ) );

	let supervisorHash;
	try {
		fs.mkdirSync( runtimeRoot, { mode: 0o700 } );
		fs.mkdirSync( profilePath, { mode: 0o700 } );
		const pauseFile = process.env.HTML_API_FUZZ_CHROME_TEST_PAUSE_AFTER_SUPERVISOR_SPAWN;
		if ( pauseFile ) {
			fs.writeFileSync(
				pauseFile,
				JSON.stringify( {
					ownerPid: process.ppid,
					supervisorPid: process.pid,
					runtimeRoot,
					profilePath,
					socketPath,
				} ) + '\n',
				{ mode: 0o600 }
			);
			while ( fs.existsSync( pauseFile ) && ! cleaning ) {
				await delay( 10 );
			}
			if ( cleaning ) {
				await done;
				return;
			}
		}
		supervisorHash = await hashFile( executable );
		if ( cleaning ) {
			await done;
			return;
		}
		if ( supervisorHash !== options[ 'expected-sha256' ] ) {
			throw new Error( 'Chrome executable changed before supervisor launch.' );
		}
		chromeCarriesOwnershipToken = true;
		chrome = spawn( executable, options.chromeArguments, {
			detached: false,
			stdio: [ 'ignore', 'ignore', 'pipe' ],
		} );
		if ( ! Number.isSafeInteger( chrome.pid ) || chrome.pid < 2 ) {
			throw new Error( 'Chrome supervisor did not receive a browser PID.' );
		}
		let readySent = false;
		let readyAllowed = false;
		let readyEndpoint = null;
		const sendReady = () => {
			if ( cleaning || readySent || ! readyAllowed || ! readyEndpoint ) {
				return;
			}
			readySent = true;
			try {
				const tree = captureProcessTree( chrome.pid, profilePath, options.token );
				snapshots.push( tree );
				emit( {
					event: 'ready',
					browserPid: chrome.pid,
					executableSha256: supervisorHash,
					endpoint: readyEndpoint,
					processes: tree,
				} );
			} catch ( error ) {
				emit( { event: 'startup-error', error: error.message } );
				cleanup( 'identity-failure' );
			}
		};
		chrome.stderr.on( 'data', ( chunk ) => {
			stderrTailBuffer = appendByteTail( stderrTailBuffer, chunk, CHROME_STDERR_BYTES );
			const stderrTail = decodeBoundedUtf8Tail( stderrTailBuffer, CHROME_STDERR_BYTES );
			const match = stderrTail.match( /DevTools listening on (ws:\/\/[^\s]+)/ );
			if ( match ) {
				readyEndpoint = match[ 1 ];
				sendReady();
			}
		} );
		chrome.once( 'error', ( error ) => {
			emit( { event: 'startup-error', error: error.message } );
			cleanup( 'spawn-error' );
		} );
		chrome.once( 'exit', ( code, signal ) => {
			clearInterval( heartbeat );
			heartbeat = null;
			emit( {
				event: 'browser-exit',
				code,
				signal,
				stderrTail: decodeBoundedUtf8Tail( stderrTailBuffer, CHROME_STDERR_BYTES ),
			} );
		} );
		heartbeat = setInterval( () => {
			if ( cleaning || ! chrome?.pid || emitter.isBackpressured() ) {
				return;
			}
			try {
				const tree = captureProcessTree( chrome.pid, profilePath, options.token );
				if ( 0 === tree.length ) {
					return;
				}
				snapshots.push( tree );
				while ( snapshots.length > 8 ) {
					snapshots.shift();
				}
				emitter.emit( { event: 'processes', processes: tree }, true );
			} catch ( _error ) {
				// Browser exit is reported by the child event. A transient ps race
				// must not terminate a healthy owner.
			}
		}, 250 );
		heartbeat.unref();
		const browserPauseFile = process.env.HTML_API_FUZZ_CHROME_TEST_PAUSE_AFTER_BROWSER_SPAWN;
		if ( browserPauseFile ) {
			fs.writeFileSync(
				browserPauseFile,
				JSON.stringify( {
					ownerPid: process.ppid,
					supervisorPid: process.pid,
					browserPid: chrome.pid,
					runtimeRoot,
					profilePath,
					socketPath,
				} ) + '\n',
				{ mode: 0o600 }
			);
			while ( fs.existsSync( browserPauseFile ) && ! cleaning ) {
				await delay( 10 );
			}
			if ( cleaning ) {
				await done;
				return;
			}
		}
		readyAllowed = true;
		sendReady();

		await done;
	} catch ( error ) {
		if ( cleaning ) {
			await done;
			return;
		}
		await cleanup( 'supervisor-error' );
		throw error;
	}
}

function parseArgs( argv ) {
	const booleanOptions = new Set( [ 'help', 'serve', 'version' ] );
	const valueOptions = new Set( [
		'engine',
		'socket',
		'chrome-executable',
		'mode',
		'input',
		'context',
		'max-nodes',
		'max-depth',
		'max-tree-bytes',
	] );
	const options = { engine: 'chrome' };
	const seen = new Set();
	for ( let index = 2; index < argv.length; index++ ) {
		const argument = argv[ index ];
		if ( ! argument.startsWith( '--' ) || 2 === argument.length || argument.includes( '=' ) ) {
			throw new Error( 'Unexpected argument: ' + argument );
		}
		const name = argument.slice( 2 );
		if ( seen.has( name ) ) {
			throw new Error( 'Duplicate option --' + name + '.' );
		}
		seen.add( name );
		if ( booleanOptions.has( name ) ) {
			options[ name ] = true;
			continue;
		}
		if ( ! valueOptions.has( name ) ) {
			throw new Error( 'Unknown option --' + name + '.' );
		}
		if ( index + 1 >= argv.length || argv[ index + 1 ].startsWith( '--' ) ) {
			throw new Error( 'Missing value for --' + name + '.' );
		}
		const value = argv[ ++index ];
		if ( '' === value ) {
			throw new Error( 'Empty value for --' + name + '.' );
		}
		options[ name ] = value;
	}
	if ( 'chrome' !== options.engine ) {
		throw new Error( '--engine must be chrome.' );
	}
	if ( options.help && ( options.serve || options.version || options.socket || options.input || options.mode || options.context || options[ 'max-nodes' ] || options[ 'max-depth' ] || options[ 'max-tree-bytes' ] || options[ 'chrome-executable' ] ) ) {
		throw new Error( '--help cannot be combined with an operation.' );
	}
	if ( options.version && ( options.serve || options.socket || options.input || options.mode || options.context || options[ 'max-nodes' ] || options[ 'max-depth' ] || options[ 'max-tree-bytes' ] ) ) {
		throw new Error( '--version cannot be combined with another operation.' );
	}
	if ( options.socket && ! options.serve ) {
		throw new Error( '--socket requires --serve.' );
	}
	if ( options.socket && ! path.isAbsolute( options.socket ) ) {
		throw new Error( '--socket must be an absolute path.' );
	}
	if ( options.socket ) {
		assertSecureSocketPath( options.socket );
	}
	if ( options.serve && ( options.input || options.mode || options.context || options[ 'max-nodes' ] || options[ 'max-depth' ] || options[ 'max-tree-bytes' ] ) ) {
		throw new Error( 'Per-render options are not valid with --serve.' );
	}
	if ( options.mode && ! [ 'full-document', 'fragment-body' ].includes( options.mode ) ) {
		throw new Error( '--mode must be full-document or fragment-body.' );
	}
	if ( options.context && ! CONTEXT_SET.has( options.context ) ) {
		throw new Error( 'Unsupported fragment context: ' + options.context + '.' );
	}
	for ( const [ name, maximum ] of [
		[ 'max-nodes', MAX_NODES ],
		[ 'max-depth', MAX_DEPTH ],
		[ 'max-tree-bytes', MAX_TREE_BYTES ],
	] ) {
		if ( undefined !== options[ name ] ) {
			const value = Number( options[ name ] );
			if ( ! Number.isSafeInteger( value ) || value < 1 || value > maximum || String( value ) !== options[ name ] ) {
				throw new Error( '--' + name + ' must be a canonical positive integer no greater than ' + maximum + '.' );
			}
		}
	}
	if ( ! options.help && ! options.version && ! options.serve ) {
		if ( ! options.input || ! options.mode ) {
			throw new Error( 'One-shot mode requires --input and --mode.' );
		}
		if ( 'full-document' === options.mode && options.context ) {
			throw new Error( '--context is only valid for fragment-body mode.' );
		}
	}
	return options;
}

class CdpWebSocket {
	constructor( socket, initialData = Buffer.alloc( 0 ), maxFrameBytes = MAX_CDP_FRAME_BYTES ) {
		this.socket = socket;
		this.maxFrameBytes = maxFrameBytes;
		this.buffer = Buffer.alloc( 0 );
		this.fragmentOpcode = null;
		this.fragments = [];
		this.fragmentBytes = 0;
		this.messageHandler = () => {};
		this.closeHandlers = [];
		this.closed = false;
		this.closeError = null;
		socket.on( 'data', ( data ) => this.consume( data ) );
		socket.on( 'close', () => this.handleClose( sessionDeathError( 'CDP WebSocket closed.' ) ) );
		socket.on( 'error', ( error ) => this.handleClose( sessionDeathError( 'CDP WebSocket I/O failed.', error ) ) );
		if ( initialData.length ) {
			this.consume( initialData );
		}
	}

	onMessage( handler ) {
		this.messageHandler = handler;
	}

	onClose( handler ) {
		this.closeHandlers.push( handler );
	}

	handleClose( error ) {
		if ( this.closed ) {
			return;
		}
		this.closed = true;
		const closed = markTransportError( error );
		this.closeError = closed;
		for ( const handler of this.closeHandlers ) {
			handler( closed );
		}
	}

	failProtocol( message, cause ) {
		this.terminate( cdpProtocolError( 'Invalid CDP WebSocket protocol: ' + message, cause ) );
	}

	consume( data ) {
		if ( this.closed ) {
			return;
		}
		if ( this.buffer.length + data.length > this.maxFrameBytes + 14 ) {
			this.failProtocol( 'input exceeded its byte limit.' );
			return;
		}
		this.buffer = Buffer.concat( [ this.buffer, data ] );
		while ( this.buffer.length >= 2 ) {
			const first = this.buffer[ 0 ];
			const second = this.buffer[ 1 ];
			const final = Boolean( first & 0x80 );
			const opcode = first & 0x0f;
			const masked = Boolean( second & 0x80 );
			if ( 0 !== ( first & 0x70 ) ) {
				this.failProtocol( 'RSV bits were set.' );
				return;
			}
			if ( ! [ 0x0, 0x1, 0x2, 0x8, 0x9, 0xA ].includes( opcode ) ) {
				this.failProtocol( 'an unknown opcode was received.' );
				return;
			}
			if ( masked ) {
				this.failProtocol( 'a server frame was masked.' );
				return;
			}
			let length = second & 0x7f;
			let offset = 2;
			if ( 126 === length ) {
				if ( opcode >= 0x8 ) {
					this.failProtocol( 'a control frame used an extended length.' );
					return;
				}
				if ( this.buffer.length < 4 ) {
					return;
				}
				length = this.buffer.readUInt16BE( 2 );
				if ( length < 126 ) {
					this.failProtocol( 'a frame used a nonminimal 16-bit length.' );
					return;
				}
				offset = 4;
			} else if ( 127 === length ) {
				if ( opcode >= 0x8 ) {
					this.failProtocol( 'a control frame used an extended length.' );
					return;
				}
				if ( this.buffer.length < 10 ) {
					return;
				}
				const longLength = this.buffer.readBigUInt64BE( 2 );
				if ( longLength < 65536n || 0n !== ( longLength & ( 1n << 63n ) ) ) {
					this.failProtocol( 'a frame used an invalid 64-bit length.' );
					return;
				}
				if ( longLength > BigInt( Number.MAX_SAFE_INTEGER ) ) {
					this.failProtocol( 'a frame length exceeded the safe integer range.' );
					return;
				}
				length = Number( longLength );
				offset = 10;
			}
			if ( length > this.maxFrameBytes ) {
				this.failProtocol( 'a frame exceeded its byte limit.' );
				return;
			}
			if ( opcode >= 0x8 && ( ! final || length > 125 ) ) {
				this.failProtocol( 'a control frame was fragmented or oversized.' );
				return;
			}
			if ( this.buffer.length < offset + length ) {
				return;
			}
			const payload = Buffer.from( this.buffer.subarray( offset, offset + length ) );
			this.buffer = this.buffer.subarray( offset + length );
			if ( 0x8 === opcode ) {
				if ( 1 === payload.length ) {
					this.failProtocol( 'a close frame carried a one-byte status.' );
					return;
				}
				if ( payload.length >= 2 ) {
					const status = payload.readUInt16BE( 0 );
					if (
						status < 1000 ||
						status > 4999 ||
						[ 1004, 1005, 1006, 1015 ].includes( status )
					) {
						this.failProtocol( 'a close frame carried a forbidden status code.' );
						return;
					}
				}
				if ( payload.length > 2 ) {
					try {
						new TextDecoder( 'utf-8', { fatal: true } ).decode( payload.subarray( 2 ) );
					} catch ( error ) {
						this.failProtocol( 'a close reason was not valid UTF-8.', error );
						return;
					}
				}
				this.sendFrame( 0x8, payload );
				this.socket.end();
				this.buffer = Buffer.alloc( 0 );
				this.fragments = [];
				this.fragmentBytes = 0;
				this.fragmentOpcode = null;
				this.handleClose( sessionDeathError( 'CDP WebSocket closed by the server.' ) );
				return;
			}
			if ( 0x9 === opcode ) {
				this.sendFrame( 0xA, payload );
				continue;
			}
			if ( 0xA === opcode ) {
				continue;
			}
			if ( 0x2 === opcode ) {
				this.failProtocol( 'a binary CDP message was received.' );
				return;
			}
			if ( 0x1 === opcode ) {
				if ( null !== this.fragmentOpcode ) {
					this.failProtocol( 'a new data frame interrupted a fragmented message.' );
					return;
				}
				this.fragmentOpcode = opcode;
				this.fragments = [ payload ];
				this.fragmentBytes = payload.length;
			} else if ( 0x0 === opcode ) {
				if ( null === this.fragmentOpcode ) {
					this.failProtocol( 'an orphan continuation frame was received.' );
					return;
				}
				if ( this.fragmentBytes + payload.length > this.maxFrameBytes ) {
					this.failProtocol( 'a fragmented message exceeded its byte limit.' );
					return;
				}
				this.fragments.push( payload );
				this.fragmentBytes += payload.length;
			}
			if ( final ) {
				if ( this.fragmentBytes > this.maxFrameBytes ) {
					this.failProtocol( 'a fragmented message exceeded its byte limit.' );
					return;
				}
				const message = Buffer.concat( this.fragments );
				const messageOpcode = this.fragmentOpcode;
				this.fragmentOpcode = null;
				this.fragments = [];
				this.fragmentBytes = 0;
				if ( 0x1 === messageOpcode ) {
					let text;
					try {
						text = new TextDecoder( 'utf-8', { fatal: true } ).decode( message );
					} catch ( error ) {
						this.failProtocol( 'a text message was not valid UTF-8.', error );
						return;
					}
					this.messageHandler( text );
				}
			}
		}
	}

	sendFrame( opcode, payload ) {
		if ( this.closed ) {
			throw this.closeError || sessionDeathError( 'Cannot write to a closed CDP WebSocket.' );
		}
		payload = Buffer.isBuffer( payload ) ? payload : Buffer.from( payload );
		let header;
		if ( payload.length < 126 ) {
			header = Buffer.alloc( 2 );
			header[ 1 ] = 0x80 | payload.length;
		} else if ( payload.length <= 0xffff ) {
			header = Buffer.alloc( 4 );
			header[ 1 ] = 0x80 | 126;
			header.writeUInt16BE( payload.length, 2 );
		} else {
			header = Buffer.alloc( 10 );
			header[ 1 ] = 0x80 | 127;
			header.writeBigUInt64BE( BigInt( payload.length ), 2 );
		}
		header[ 0 ] = 0x80 | opcode;
		const mask = crypto.randomBytes( 4 );
		const masked = Buffer.allocUnsafe( payload.length );
		for ( let i = 0; i < payload.length; i++ ) {
			masked[ i ] = payload[ i ] ^ mask[ i % 4 ];
		}
		this.socket.write( Buffer.concat( [ header, mask, masked ] ) );
	}

	sendJson( value ) {
		this.sendFrame( 0x1, Buffer.from( JSON.stringify( value ), 'utf8' ) );
	}

	close() {
		if ( ! this.closed ) {
			this.sendFrame( 0x8, Buffer.alloc( 0 ) );
			this.socket.end();
		}
	}

	terminate( error ) {
		this.handleClose( error );
		this.socket.destroy();
	}
}

function connectWebSocket( endpoint ) {
	return new Promise( ( resolve, reject ) => {
		const url = new URL( endpoint );
		if ( 'ws:' !== url.protocol ) {
			reject( new Error( `Unsupported CDP WebSocket URL: ${ endpoint }` ) );
			return;
		}
		const key = crypto.randomBytes( 16 ).toString( 'base64' );
		const expectedAccept = crypto.createHash( 'sha1' )
			.update( `${ key }258EAFA5-E914-47DA-95CA-C5AB0DC85B11` )
			.digest( 'base64' );
		const socket = net.createConnection( { host: url.hostname, port: Number( url.port ) } );
		let headers = Buffer.alloc( 0 );
		let settled = false;
		let timer = null;
		const fail = ( error ) => {
			if ( ! settled ) {
				settled = true;
				clearTimeout( timer );
				socket.destroy();
				reject( error );
			}
		};
		timer = setTimeout( () => fail( transportError( 'CDP WebSocket handshake timed out.' ) ), 5000 );
		socket.once( 'error', fail );
		socket.once( 'connect', () => {
			const target = `${ url.pathname }${ url.search }`;
			socket.write(
				`GET ${ target } HTTP/1.1\r\n` +
				`Host: ${ url.host }\r\n` +
				'Upgrade: websocket\r\n' +
				'Connection: Upgrade\r\n' +
				`Sec-WebSocket-Key: ${ key }\r\n` +
				'Sec-WebSocket-Version: 13\r\n\r\n'
			);
		} );
		const onData = ( data ) => {
			if ( headers.length + data.length > 65536 ) {
				fail( new Error( 'CDP WebSocket handshake headers exceeded 64 KiB.' ) );
				return;
			}
			headers = Buffer.concat( [ headers, data ] );
			const boundary = headers.indexOf( '\r\n\r\n' );
			if ( -1 === boundary ) {
				return;
			}
			const headerText = headers.subarray( 0, boundary ).toString( 'latin1' );
			const remainder = headers.subarray( boundary + 4 );
			if ( ! /^HTTP\/1\.1 101\b/.test( headerText ) ) {
				fail( new Error( `CDP WebSocket handshake failed: ${ headerText.split( '\r\n' )[ 0 ] }` ) );
				return;
			}
			const acceptMatch = headerText.match( /^Sec-WebSocket-Accept:\s*(.+)$/im );
			if ( ! acceptMatch || acceptMatch[ 1 ].trim() !== expectedAccept ) {
				fail( new Error( 'CDP WebSocket handshake returned an invalid accept key.' ) );
				return;
			}
			settled = true;
			clearTimeout( timer );
			socket.removeListener( 'data', onData );
			socket.removeListener( 'error', fail );
			resolve( new CdpWebSocket( socket, remainder ) );
		};
		socket.on( 'data', onData );
	} );
}

class CdpClient {
	constructor( websocket ) {
		this.websocket = websocket;
		this.nextId = 0;
		this.pending = new Map();
		this.waiters = [];
		websocket.onMessage( ( message ) => this.receive( message ) );
		websocket.onClose( ( error ) => this.failAll( error ) );
	}

	receive( message ) {
		let decoded;
		try {
			decoded = JSON.parse( message );
		} catch ( error ) {
			this.failProtocol( cdpProtocolError( `Chrome returned invalid CDP JSON: ${ error.message }`, error ) );
			return;
		}
		try {
			this.validateEnvelope( decoded );
		} catch ( error ) {
			this.failProtocol( error );
			return;
		}
		if ( Object.hasOwn( decoded, 'id' ) ) {
			if ( ! this.pending.has( decoded.id ) ) {
				this.failProtocol( cdpProtocolError( 'Chrome returned an unknown or duplicate CDP response id.' ) );
				return;
			}
			const pending = this.pending.get( decoded.id );
			const responseHasSession = Object.hasOwn( decoded, 'sessionId' );
			if (
				( undefined === pending.sessionId && responseHasSession ) ||
				( undefined !== pending.sessionId && decoded.sessionId !== pending.sessionId )
			) {
				this.failProtocol( cdpProtocolError( 'Chrome returned a CDP response on the wrong session route.' ) );
				return;
			}
			this.pending.delete( decoded.id );
			clearTimeout( pending.timer );
			if ( decoded.error ) {
				const error = new Error( `CDP ${ pending.method } failed: ${ decoded.error.message }` );
				error.code = decoded.error.code;
				if ( /(?:Target closed|Session with given id not found|No target with given id)/i.test( decoded.error.message || '' ) ) {
					error.transportFailure = true;
					error.recoverableSessionDeath = true;
				}
				pending.reject( error );
			} else {
				pending.resolve( decoded.result );
			}
			return;
		}
		if ( Object.hasOwn( decoded, 'method' ) ) {
			for ( const waiter of [ ...this.waiters ] ) {
				if ( waiter.method === decoded.method && ( ! waiter.sessionId || waiter.sessionId === decoded.sessionId ) ) {
					this.waiters.splice( this.waiters.indexOf( waiter ), 1 );
					clearTimeout( waiter.timer );
					waiter.resolve( decoded.params || {} );
				}
			}
		}
	}

	validateEnvelope( decoded ) {
		if ( null === decoded || 'object' !== typeof decoded || Array.isArray( decoded ) ) {
			throw cdpProtocolError( 'Chrome returned a non-object CDP envelope.' );
		}
		const allowed = new Set( [ 'id', 'method', 'params', 'result', 'error', 'sessionId' ] );
		for ( const key of Object.keys( decoded ) ) {
			if ( ! allowed.has( key ) ) {
				throw cdpProtocolError( 'Chrome returned an unknown CDP envelope field: ' + key + '.' );
			}
		}
		if ( undefined !== decoded.sessionId && 'string' !== typeof decoded.sessionId ) {
			throw cdpProtocolError( 'Chrome returned a non-string CDP sessionId.' );
		}
		const hasId = Object.hasOwn( decoded, 'id' );
		const hasMethod = Object.hasOwn( decoded, 'method' );
		if ( hasId === hasMethod ) {
			throw cdpProtocolError( 'Chrome returned an ambiguous CDP envelope.' );
		}
		if ( hasId ) {
			if ( ! Number.isSafeInteger( decoded.id ) || decoded.id < 1 ) {
				throw cdpProtocolError( 'Chrome returned an invalid CDP response id.' );
			}
			const hasResult = Object.hasOwn( decoded, 'result' );
			const hasError = Object.hasOwn( decoded, 'error' );
			if ( hasResult === hasError || Object.hasOwn( decoded, 'params' ) ) {
				throw cdpProtocolError( 'Chrome returned an invalid CDP response envelope.' );
			}
			if ( hasResult && ( null === decoded.result || 'object' !== typeof decoded.result || Array.isArray( decoded.result ) ) ) {
				throw cdpProtocolError( 'Chrome returned a non-object CDP result.' );
			}
			if (
				hasError &&
				(
					null === decoded.error ||
					'object' !== typeof decoded.error ||
					Array.isArray( decoded.error ) ||
					! Number.isSafeInteger( decoded.error.code ) ||
					'string' !== typeof decoded.error.message
				)
			) {
				throw cdpProtocolError( 'Chrome returned a malformed CDP error.' );
			}
			return;
		}
		if (
			'string' !== typeof decoded.method ||
			'' === decoded.method ||
			Object.hasOwn( decoded, 'result' ) ||
			Object.hasOwn( decoded, 'error' ) ||
			(
				Object.hasOwn( decoded, 'params' ) &&
				( null === decoded.params || 'object' !== typeof decoded.params || Array.isArray( decoded.params ) )
			)
		) {
			throw cdpProtocolError( 'Chrome returned an invalid CDP event envelope.' );
		}
	}

	failProtocol( error ) {
		this.failAll( error );
		if ( 'function' === typeof this.websocket.terminate ) {
			this.websocket.terminate( error );
		} else {
			this.websocket.close?.();
		}
	}

	send( method, params = {}, sessionId = undefined, timeoutMs = 15000 ) {
		const id = ++this.nextId;
		return new Promise( ( resolve, reject ) => {
			const timer = setTimeout( () => {
				this.pending.delete( id );
				reject( cdpTimeoutError( method ) );
			}, timeoutMs );
			this.pending.set( id, { method, sessionId, resolve, reject, timer } );
			const message = { id, method, params };
			if ( sessionId ) {
				message.sessionId = sessionId;
			}
			try {
				this.websocket.sendJson( message );
			} catch ( error ) {
				clearTimeout( timer );
				this.pending.delete( id );
				reject( error );
			}
		} );
	}

	waitFor( method, sessionId, timeoutMs = 15000 ) {
		return new Promise( ( resolve, reject ) => {
			const waiter = { method, sessionId, resolve, reject, timer: null };
			waiter.timer = setTimeout( () => {
				this.waiters.splice( this.waiters.indexOf( waiter ), 1 );
				reject( cdpTimeoutError( method, 'event' ) );
			}, timeoutMs );
			this.waiters.push( waiter );
		} );
	}

	failAll( error ) {
		for ( const pending of this.pending.values() ) {
			clearTimeout( pending.timer );
			pending.reject( error );
		}
		this.pending.clear();
		for ( const waiter of this.waiters ) {
			clearTimeout( waiter.timer );
			waiter.reject( error );
		}
		this.waiters = [];
	}

	close() {
		this.websocket.close();
	}
}

function browserRender( args ) {
	const HTML_NAMESPACE = 'http://www.w3.org/1999/xhtml';
	const SVG_NAMESPACE = 'http://www.w3.org/2000/svg';
	const MATH_NAMESPACE = 'http://www.w3.org/1998/Math/MathML';
	const XLINK_NAMESPACE = 'http://www.w3.org/1999/xlink';
	const XML_NAMESPACE = 'http://www.w3.org/XML/1998/namespace';
	const XMLNS_NAMESPACE = 'http://www.w3.org/2000/xmlns/';
	const encoder = new TextEncoder();
	const chunks = [];
	let pending = '';
	let treeBytes = 0;
	let nodeCount = 0;

	function limit( failureClass, message ) {
		const error = new Error( message );
		error.failureClass = failureClass;
		throw error;
	}

	function utf8Length( value ) {
		let bytes = 0;
		for ( const character of value ) {
			const code = character.codePointAt( 0 );
			bytes += code <= 0x7f ? 1 : code <= 0x7ff ? 2 : code <= 0xffff ? 3 : 4;
		}
		return bytes;
	}

	function flush() {
		if ( '' !== pending ) {
			chunks.push( encoder.encode( pending ) );
			pending = '';
		}
	}

	function append( value ) {
		const encodedLength = utf8Length( value );
		if ( treeBytes + encodedLength > args.maxTreeBytes ) {
			limit( 'tree-byte-limit-exceeded', 'Canonical tree byte limit exceeded.' );
		}
		treeBytes += encodedLength;
		pending += value;
		if ( pending.length >= 65536 ) {
			flush();
		}
	}

	function appendEscaped( value ) {
		for ( const character of String( value ) ) {
			switch ( character ) {
				case '\n': append( '\\n' ); break;
				case '\r': append( '\\r' ); break;
				case '\t': append( '\\t' ); break;
				case '\0': append( '\\0' ); break;
				case '\\': append( '\\\\' ); break;
				case '"': append( '\\"' ); break;
				default: {
					const code = character.codePointAt( 0 );
					if ( code < 0x20 || 0x7f === code ) {
						append( '\\x' + code.toString( 16 ).toUpperCase().padStart( 2, '0' ) );
					} else {
						append( character );
					}
				}
			}
		}
	}

	function escapedForSort( value ) {
		let output = '';
		for ( const character of String( value ) ) {
			switch ( character ) {
				case '\n': output += '\\n'; break;
				case '\r': output += '\\r'; break;
				case '\t': output += '\\t'; break;
				case '\0': output += '\\0'; break;
				case '\\': output += '\\\\'; break;
				case '"': output += '\\"'; break;
				default: {
					const code = character.codePointAt( 0 );
					output += code < 0x20 || 0x7f === code
						? '\\x' + code.toString( 16 ).toUpperCase().padStart( 2, '0' )
						: character;
				}
			}
		}
		return output;
	}

	function compareUtf8( left, right ) {
		const a = encoder.encode( left );
		const b = encoder.encode( right );
		const length = Math.min( a.length, b.length );
		for ( let index = 0; index < length; index++ ) {
			if ( a[ index ] !== b[ index ] ) {
				return a[ index ] - b[ index ];
			}
		}
		return a.length - b.length;
	}

	function compareDisplayNames( left, right ) {
		const leftHasColon = left.includes( ':' );
		const rightHasColon = right.includes( ':' );
		if ( leftHasColon !== rightHasColon ) {
			return leftHasColon ? 1 : -1;
		}
		const leftHasSpace = left.includes( ' ' );
		const rightHasSpace = right.includes( ' ' );
		if ( leftHasSpace !== rightHasSpace ) {
			return leftHasSpace ? 1 : -1;
		}
		return compareUtf8( left, right );
	}

	function elementName( element ) {
		if ( HTML_NAMESPACE === element.namespaceURI ) {
			return element.localName;
		}
		if ( SVG_NAMESPACE === element.namespaceURI ) {
			return 'svg ' + element.localName;
		}
		if ( MATH_NAMESPACE === element.namespaceURI ) {
			return 'math ' + element.localName;
		}
		return element.nodeName;
	}

	function attributeName( attribute ) {
		if ( XLINK_NAMESPACE === attribute.namespaceURI ) {
			return 'xlink ' + attribute.localName;
		}
		if ( XML_NAMESPACE === attribute.namespaceURI ) {
			return 'xml ' + attribute.localName;
		}
		if ( XMLNS_NAMESPACE === attribute.namespaceURI ) {
			return 'xmlns ' + attribute.localName;
		}
		return attribute.name;
	}

	function appendIndent( indent ) {
		append( '  '.repeat( indent ) );
	}

	function renderAttributes( element, indent ) {
		const records = Array.from( element.attributes, ( attribute ) => {
			const displayName = attributeName( attribute );
			return {
				attribute,
				sortName: escapedForSort( displayName ),
				renderName: displayName,
			};
		} );
		records.sort( ( left, right ) => {
			const sorted = compareDisplayNames( left.sortName, right.sortName );
			return 0 !== sorted ? sorted : compareDisplayNames( left.renderName, right.renderName );
		} );
		for ( const record of records ) {
			appendIndent( indent );
			appendEscaped( record.renderName );
			append( '="' );
			appendEscaped( record.attribute.value );
			append( '"\n' );
		}
	}

	function renderNode( node, indent ) {
		nodeCount++;
		if ( nodeCount > args.maxNodes ) {
			limit( 'node-limit-exceeded', 'DOM node limit exceeded.' );
		}
		if ( indent > args.maxDepth ) {
			limit( 'depth-limit-exceeded', 'DOM depth limit exceeded.' );
		}
		switch ( node.nodeType ) {
			case Node.DOCUMENT_TYPE_NODE:
				append( '<!DOCTYPE ' );
				appendEscaped( node.name );
				if ( node.publicId || node.systemId ) {
					append( ' "' );
					appendEscaped( node.publicId );
					append( '" "' );
					appendEscaped( node.systemId );
					append( '"' );
				}
				append( '>\n' );
				return;
			case Node.ELEMENT_NODE:
				appendIndent( indent );
				append( '<' );
				appendEscaped( elementName( node ) );
				append( '>\n' );
				renderAttributes( node, indent + 1 );
				if ( HTML_NAMESPACE === node.namespaceURI && 'template' === node.localName ) {
					appendIndent( indent + 1 );
					append( 'content\n' );
					for ( const child of node.content.childNodes ) {
						renderNode( child, indent + 2 );
					}
					return;
				}
				for ( const child of node.childNodes ) {
					renderNode( child, indent + 1 );
				}
				return;
			case Node.TEXT_NODE:
			case Node.CDATA_SECTION_NODE:
				if ( '' !== node.nodeValue ) {
					appendIndent( indent );
					append( '"' );
					appendEscaped( node.nodeValue );
					append( '"\n' );
				}
				return;
			case Node.COMMENT_NODE:
				appendIndent( indent );
				append( '<!-- ' );
				appendEscaped( node.nodeValue );
				append( ' -->\n' );
				return;
			case Node.PROCESSING_INSTRUCTION_NODE:
				appendIndent( indent );
				append( '<?' );
				appendEscaped( node.target );
				append( ' ' );
				appendEscaped( node.data );
				append( '?>\n' );
				return;
			default: {
				const error = new Error( 'Unexpected DOM node type ' + node.nodeType + '.' );
				error.failureClass = 'oracle-renderer-error';
				throw error;
			}
		}
	}

	function encodeBase64() {
		flush();
		const bytes = new Uint8Array( treeBytes );
		let offset = 0;
		for ( const chunk of chunks ) {
			bytes.set( chunk, offset );
			offset += chunk.length;
		}
		let binary = '';
		for ( let index = 0; index < bytes.length; index += 32768 ) {
			binary += String.fromCharCode( ...bytes.subarray( index, Math.min( index + 32768, bytes.length ) ) );
		}
		return btoa( binary );
	}

	try {
		let roots;
		if ( args.testProcessingInstruction ) {
			const xml = new DOMParser().parseFromString( '<root/>', 'application/xml' );
			roots = [ xml.createProcessingInstruction(
				args.testProcessingInstruction.target,
				args.testProcessingInstruction.data
			) ];
		} else if ( 'full-document' === args.mode ) {
			const parsed = new DOMParser().parseFromString( args.html, 'text/html' );
			roots = parsed.childNodes;
		} else {
			const owner = new DOMParser().parseFromString(
				'<!doctype html><html><head></head><body></body></html>',
				'text/html'
			);
			const lower = args.context.toLowerCase();
			let contextElement;
			if ( 'svg' === lower ) {
				contextElement = owner.createElementNS( SVG_NAMESPACE, 'svg' );
			} else if ( 'math' === lower ) {
				contextElement = owner.createElementNS( MATH_NAMESPACE, 'math' );
			} else {
				contextElement = owner.createElementNS( HTML_NAMESPACE, lower );
			}
			const range = owner.createRange();
			range.selectNodeContents( contextElement );
			roots = range.createContextualFragment( args.html ).childNodes;
		}
		for ( const child of roots ) {
			renderNode( child, 0 );
		}
		append( '\n' );
		return {
			status: 'ok',
			treeBase64: encodeBase64(),
			treeBytes,
			nodeCount,
		};
	} catch ( error ) {
		return {
			status: error.failureClass?.endsWith( '-limit-exceeded' ) ? 'limit' : 'error',
			failureClass: error.failureClass || 'oracle-renderer-error',
			error: error.message || String( error ),
			nodeCount,
			treeBytes,
		};
	}
}

class ChromeOracle {
	constructor( options, socketPath = '' ) {
		this.options = options;
		this.socketPath = socketPath;
		this.platform = platformConfiguration();
		this.installRoot = path.resolve(
			process.env.HTML_API_FUZZ_CHROME_INSTALL_ROOT ||
			path.join( SCRIPT_DIR, '.chrome-for-testing' )
		);
		this.defaultExecutable = path.join(
			this.installRoot,
			PINNED_CHROME_VERSION,
			this.platform.platform,
			this.platform.archiveDirectory,
			this.platform.executableRelative
		);
		const optionExecutable = options[ 'chrome-executable' ];
		const environmentExecutable = process.env.HTML_API_FUZZ_CHROME_EXECUTABLE;
		if ( optionExecutable && environmentExecutable && path.resolve( optionExecutable ) !== path.resolve( environmentExecutable ) ) {
			throw new Error( '--chrome-executable conflicts with HTML_API_FUZZ_CHROME_EXECUTABLE.' );
		}
		this.executable = path.resolve( optionExecutable || environmentExecutable || this.defaultExecutable );
		this.usesDefaultInstallation = this.executable === path.resolve( this.defaultExecutable );
		this.expectedArchiveSha256 = manifestDigest(
			path.join( SCRIPT_DIR, 'SHA256SUMS' ),
			'chrome-' + PINNED_CHROME_VERSION + '-' + this.platform.platform + '.zip'
		);
		this.expectedExecutableSha256 = manifestDigest(
			path.join( SCRIPT_DIR, 'EXECUTABLE_SHA256SUMS' ),
			this.platform.platform + '.executable'
		);
		this.scriptPath = fs.realpathSync( __filename );
		this.nodeExecutablePath = fs.realpathSync( process.execPath );
		this.durableIdentity = {
			schemaVersion: 1,
			kind: 'chrome-cdp',
			platform: this.platform.platform,
			pinnedChromeVersion: PINNED_CHROME_VERSION,
			chromeArchiveSha256: this.expectedArchiveSha256,
			expectedChromeExecutableSha256: this.expectedExecutableSha256,
			chromeExecutableSha256: this.expectedExecutableSha256,
			oracleScriptSha256: hashFileSync( this.scriptPath ),
			fragmentContextsSha256: CONTEXT_FILE_SHA256,
			fragmentContexts: Object.freeze( [ ...CONTEXTS ] ),
			nodeExecutableSha256: hashFileSync( this.nodeExecutablePath ),
			nodeVersion: process.version,
		};
		this.chromeVersion = null;
		this.runtimeRoot = null;
		this.profilePath = null;
		this.ownershipToken = null;
		this.supervisor = null;
		this.supervisorStderr = '';
		this.supervisorEventsBuffer = Buffer.alloc( 0 );
		this.processSnapshots = [];
		this.browserPid = null;
		this.endpoint = null;
		this.websocket = null;
		this.cdp = null;
		this.targetId = null;
		this.sessionId = null;
		this.browserInstance = 0;
		this.startPromise = null;
		this.closePromise = null;
		this.renderQueue = Promise.resolve();
		this.closing = false;
		this.healthy = false;
		this.lastTransportError = null;
		this.intentionalSupervisorExit = false;
		this.fatalInfrastructure = false;
		this.fatalHandler = null;
	}

	setFatalHandler( handler ) {
		this.fatalHandler = handler;
	}

	metadata() {
		const identity = { ...this.durableIdentity };
		if ( this.chromeVersion ) {
			identity.chromeVersion = this.chromeVersion;
		}
		const transport = {
			replayExcluded: true,
			ownerPid: process.pid,
			chromeExecutablePath: this.executable,
			oracleScriptPath: this.scriptPath,
			nodeExecutablePath: this.nodeExecutablePath,
		};
		if ( this.runtimeRoot ) {
			transport.runtimeRoot = this.runtimeRoot;
		}
		if ( this.profilePath ) {
			transport.profilePath = this.profilePath;
		}
		if ( this.socketPath ) {
			transport.socketPath = this.socketPath;
		}
		if ( this.endpoint ) {
			transport.debugEndpoint = this.endpoint;
		}
		if ( this.supervisor?.pid ) {
			transport.supervisorPid = this.supervisor.pid;
		}
		if ( this.browserPid ) {
			transport.browserPid = this.browserPid;
		}
		if ( this.browserInstance ) {
			transport.browserInstance = this.browserInstance;
		}
		return {
			kind: 'chrome-cdp',
			engine: 'chrome',
			available: this.healthy,
			identity,
			transport,
		};
	}

	expectedMarker() {
		return [
			'schema=1',
			'version=' + PINNED_CHROME_VERSION,
			'platform=' + this.platform.platform,
			'archive_sha256=' + this.expectedArchiveSha256,
			'executable_sha256=' + this.expectedExecutableSha256,
			'',
		].join( '\n' );
	}

	markerPath() {
		return path.join(
			this.installRoot,
			PINNED_CHROME_VERSION,
			this.platform.platform,
			'.html-api-fuzz-verified'
		);
	}

	readMarkerSnapshot() {
		if ( ! this.usesDefaultInstallation ) {
			return null;
		}
		const markerPath = this.markerPath();
		const identity = fileIdentitySnapshot( markerPath );
		const marker = fs.readFileSync( markerPath, 'utf8' );
		if ( marker !== this.expectedMarker() ) {
			throw new Error( 'Default Chrome installation marker does not match checked-in trust anchors.' );
		}
		return { contents: marker, identity };
	}

	async authenticateExecutable() {
		if ( ! fs.existsSync( this.executable ) ) {
			throw new Error(
				'Pinned Chrome for Testing is not installed at ' + this.executable +
				'. Run ' + path.join( SCRIPT_DIR, 'install.sh' ) + '.'
			);
		}
		const executableIdentity = fileIdentitySnapshot( this.executable );
		this.executable = executableIdentity.realpath;
		const markerBefore = this.readMarkerSnapshot();
		const hashBefore = await hashFile( this.executable );
		if ( hashBefore !== this.expectedExecutableSha256 ) {
			throw new Error( 'Chrome executable does not match the checked-in SHA-256 trust anchor.' );
		}
		return { hash: hashBefore, executableIdentity, marker: markerBefore };
	}

	planRuntime() {
		const token = crypto.randomBytes( 16 ).toString( 'hex' );
		const root = path.join( os.tmpdir(), 'html-api-fuzz-chrome-' + process.pid + '-' + token );
		if ( fs.existsSync( root ) ) {
			throw new Error( 'Refusing pre-existing Chrome runtime path.' );
		}
		this.runtimeRoot = root;
		this.profilePath = path.join( root, 'profile' );
		this.ownershipToken = token;
	}

	chromeArguments() {
		const args = [
			'--headless=new',
			'--remote-debugging-port=0',
			'--remote-debugging-address=127.0.0.1',
			'--remote-allow-origins=*',
			'--user-data-dir=' + this.profilePath,
			'--html-api-fuzz-owner-token=' + this.ownershipToken,
			'--no-first-run',
			'--no-default-browser-check',
			'--disable-background-networking',
			'--disable-component-update',
			'--disable-domain-reliability',
			'--disable-features=OptimizationHints,MediaRouter,Translate',
			'--disable-sync',
			'--metrics-recording-only',
			'--mute-audio',
			'--no-pings',
			'--password-store=basic',
			'--use-mock-keychain',
			'about:blank',
		];
		if ( 'linux' === process.platform && 0 === process.getuid?.() ) {
			args.unshift( '--no-sandbox' );
		}
		return args;
	}

	consumeSupervisorOutput( chunk, state, ready ) {
		if ( state.supervisorProtocolFailure ) {
			return;
		}
		this.supervisorEventsBuffer = Buffer.concat( [ this.supervisorEventsBuffer, chunk ] );
		if ( this.supervisorEventsBuffer.length > 1024 * 1024 ) {
			this.failSupervisorProtocol(
				transportError( 'Chrome supervisor event frame exceeded 1 MiB.' ),
				state,
				ready
			);
			return;
		}
		for ( ;; ) {
			const newline = this.supervisorEventsBuffer.indexOf( 0x0a );
			if ( newline < 0 ) {
				break;
			}
			const line = this.supervisorEventsBuffer.subarray( 0, newline );
			this.supervisorEventsBuffer = this.supervisorEventsBuffer.subarray( newline + 1 );
			let event;
			try {
				event = JSON.parse( JSON_FRAME_DECODER.decode( line ) );
			} catch ( error ) {
				this.failSupervisorProtocol(
					transportError( 'Chrome supervisor emitted invalid UTF-8 JSON.', error ),
					state,
					ready
				);
				return;
			}
			try {
				this.validateSupervisorEvent( event, state );
			} catch ( error ) {
				this.failSupervisorProtocol( transportError( error.message, error ), state, ready );
				return;
			}
			if ( 'ready' === event.event || 'processes' === event.event ) {
				this.processSnapshots = retainProcessSnapshot( state, event.processes );
			}
			if ( 'ready' === event.event ) {
				state.readyEventSeen = true;
				ready.resolve( event );
			} else if ( 'startup-error' === event.event ) {
				const failure = transportError( event.error || 'Chrome supervisor startup failed.' );
				state.supervisorExit = failure;
				state.rejectStartup?.( failure );
				ready.reject( failure );
			} else if ( 'browser-exit' === event.event && this.supervisor === state.supervisor ) {
				const failure = sessionDeathError(
					'Chrome exited during oracle operation (code ' + event.code + ', signal ' + event.signal + ').'
				);
				state.browserExit = failure;
				state.rejectStartup?.( failure );
				this.healthy = false;
				this.lastTransportError = failure;
			}
		}
	}

	validateSupervisorEvent( event, state ) {
		if ( ! event || 'object' !== typeof event || Array.isArray( event ) || 'string' !== typeof event.event ) {
			throw new Error( 'Chrome supervisor event must be an object with a string event name.' );
		}
		switch ( event.event ) {
			case 'ready':
				if (
					state.readyEventSeen || state.active ||
					! hasExactOwnKeys( event, [ 'event', 'browserPid', 'executableSha256', 'endpoint', 'processes' ] ) ||
					! Number.isSafeInteger( event.browserPid ) || event.browserPid < 2 ||
					! /^[0-9a-f]{64}$/.test( event.executableSha256 ) ||
					'string' !== typeof event.endpoint || Buffer.byteLength( event.endpoint, 'utf8' ) > 4096 ||
					! isValidProcessSnapshot( event.processes ) ||
					! event.processes.some( ( record ) => record.pid === event.browserPid )
				) {
					throw new Error( 'Chrome supervisor emitted an invalid or duplicate ready event.' );
				}
				break;
			case 'processes':
				if ( ! hasExactOwnKeys( event, [ 'event', 'processes' ] ) || ! isValidProcessSnapshot( event.processes ) ) {
					throw new Error( 'Chrome supervisor emitted an invalid process snapshot event.' );
				}
				break;
			case 'startup-error':
				if (
					state.active || ! hasExactOwnKeys( event, [ 'event', 'error' ] ) ||
					'string' !== typeof event.error || 0 === event.error.length ||
					Buffer.byteLength( event.error, 'utf8' ) > 65536
				) {
					throw new Error( 'Chrome supervisor emitted an invalid startup-error event.' );
				}
				break;
			case 'browser-exit':
				if (
					! hasExactOwnKeys( event, [ 'event', 'code', 'signal', 'stderrTail' ] ) ||
					! ( null === event.code || Number.isSafeInteger( event.code ) ) ||
					! ( null === event.signal || ( 'string' === typeof event.signal && event.signal.length <= 64 ) ) ||
					'string' !== typeof event.stderrTail || Buffer.byteLength( event.stderrTail, 'utf8' ) > CHROME_STDERR_BYTES
				) {
					throw new Error( 'Chrome supervisor emitted an invalid browser-exit event.' );
				}
				break;
			case 'cleaned':
				if (
					( ! state.intentionalExit && ! state.supervisor?.__htmlApiFuzzIntentional ) ||
					! hasExactOwnKeys( event, [ 'event', 'reason' ] ) ||
					'string' !== typeof event.reason || 0 === event.reason.length || event.reason.length > 256
				) {
					throw new Error( 'Chrome supervisor emitted an invalid cleaned event.' );
				}
				break;
			default:
				throw new Error( 'Chrome supervisor emitted an unknown event: ' + event.event + '.' );
		}
	}

	failSupervisorProtocol( failure, state, ready ) {
		if ( state.supervisorProtocolFailure ) {
			return;
		}
		failure.failureClass = 'oracle-infrastructure-failure';
		state.supervisorProtocolFailure = failure;
		this.supervisorEventsBuffer = Buffer.alloc( 0 );
		ready.reject( failure );
		state.rejectStartup?.( failure );
		if ( state.active && this.supervisor === state.supervisor && ! this.closing ) {
			void this.handleSupervisorInfrastructureFailure( failure );
		}
	}

	async start() {
		if ( this.fatalInfrastructure ) {
			throw transportError( 'Chrome oracle ownership infrastructure failed.' );
		}
		if ( this.closing ) {
			throw new Error( 'Chrome oracle is shutting down.' );
		}
		if ( this.healthy && this.cdp && this.sessionId && this.supervisor ) {
			return;
		}
		if ( this.startPromise ) {
			return this.startPromise;
		}
		this.startPromise = ( async () => {
			await this.resetState( false );
			await this.startChrome();
		} )();
		try {
			await this.startPromise;
		} finally {
			this.startPromise = null;
		}
	}

	async startChrome() {
		const startupDeadline = Date.now() + 30000;
		const state = {
			supervisor: null,
			runtimeRoot: null,
			profilePath: null,
			ownershipToken: null,
			processSnapshots: [],
			browserPid: null,
			endpoint: null,
			websocket: null,
			cdp: null,
			targetId: null,
			sessionId: null,
			intentionalExit: false,
			active: false,
			supervisorExit: null,
			browserExit: null,
			readyEventSeen: false,
			supervisorProtocolFailure: null,
			rejectStartup: null,
		};
		try {
			const ownerSnapshot = await this.authenticateExecutable();
			this.planRuntime();
			state.runtimeRoot = this.runtimeRoot;
			state.profilePath = this.profilePath;
			state.ownershipToken = this.ownershipToken;
			const environment = { ...process.env, [ INTERNAL_SUPERVISOR_ENV ]: this.ownershipToken };
			const supervisorArguments = [
				__filename,
				'--internal-supervisor',
				'--token', this.ownershipToken,
				'--chrome-executable', this.executable,
				'--expected-sha256', this.expectedExecutableSha256,
				'--profile', this.profilePath,
				'--runtime-root', this.runtimeRoot,
				'--socket', this.socketPath,
				'--',
				...this.chromeArguments(),
			];
			state.supervisor = spawn(
				process.execPath,
				supervisorArguments,
				{ detached: false, stdio: [ 'pipe', 'pipe', 'pipe' ], env: environment }
			);
			if ( ! Number.isSafeInteger( state.supervisor.pid ) || state.supervisor.pid < 2 ) {
				throw new Error( 'Chrome owner did not receive a supervisor PID.' );
			}
			this.supervisor = state.supervisor;
			this.supervisorStderr = '';
			this.supervisorEventsBuffer = Buffer.alloc( 0 );
			state.supervisor.stdin.on( 'error', ( error ) => {
				if ( ! [ 'EPIPE', 'ERR_STREAM_DESTROYED' ].includes( error.code ) ) {
					this.lastTransportError = transportError( 'Chrome supervisor control pipe failed.', error );
				}
			} );
			const ready = {};
			let startupSettled = false;
			const startupAbortPromise = new Promise( ( _resolve, reject ) => {
				state.rejectStartup = ( error ) => {
					if ( ! startupSettled ) {
						startupSettled = true;
						reject( error );
					}
				};
			} );
			const raceStartup = ( promise ) => {
				const remaining = startupDeadline - Date.now();
				if ( remaining <= 0 ) {
					return Promise.reject( transportError( 'Chrome startup exceeded its absolute deadline.' ) );
				}
				let deadlineTimer;
				const deadline = new Promise( ( _resolve, reject ) => {
					deadlineTimer = setTimeout(
						() => reject( transportError( 'Chrome startup exceeded its absolute deadline.' ) ),
						remaining
					);
				} );
				return Promise.race( [ promise, startupAbortPromise, deadline ] ).finally(
					() => clearTimeout( deadlineTimer )
				);
			};
			const readyPromise = new Promise( ( resolve, reject ) => {
				let settled = false;
				ready.resolve = ( value ) => {
					if ( ! settled ) {
						settled = true;
						resolve( value );
					}
				};
				ready.reject = ( error ) => {
					if ( ! settled ) {
						settled = true;
						reject( error );
					}
				};
			} );
			state.supervisor.stdout.on( 'data', ( chunk ) => this.consumeSupervisorOutput( chunk, state, ready ) );
			state.supervisor.stderr.on( 'data', ( chunk ) => {
				this.supervisorStderr = ( this.supervisorStderr + chunk.toString( 'utf8' ) ).slice( -CHROME_STDERR_BYTES );
			} );
			state.supervisor.once( 'error', ( error ) => {
				const failure = transportError( 'Chrome supervisor process failed.', error );
				state.supervisorExit = failure;
				state.rejectStartup( failure );
				ready.reject( failure );
				if ( state.active && this.supervisor === state.supervisor && ! this.closing ) {
					void this.handleSupervisorInfrastructureFailure( failure );
				}
			} );
			state.supervisor.once( 'exit', ( code, signal ) => {
				const failure = transportError(
						'Chrome supervisor exited before startup (code ' + code + ', signal ' + signal + '). ' +
						this.supervisorStderr
					);
				state.supervisorExit = failure;
				state.rejectStartup( failure );
				ready.reject( failure );
				if (
					state.active &&
					this.supervisor === state.supervisor &&
					! state.supervisor.__htmlApiFuzzIntentional &&
					! this.closing
				) {
					void this.handleUnexpectedSupervisorExit( code, signal );
				}
			} );

			const timeout = new Promise( ( _resolve, reject ) => {
				setTimeout( () => reject( transportError( 'Chrome supervisor startup timed out.' ) ), 15000 ).unref();
			} );
			const event = await raceStartup( Promise.race( [ readyPromise, timeout ] ) );
			if (
				event.executableSha256 !== ownerSnapshot.hash ||
				event.executableSha256 !== this.expectedExecutableSha256 ||
				! Number.isSafeInteger( event.browserPid ) ||
				! Array.isArray( event.processes )
			) {
				throw new Error( 'Chrome supervisor handshake did not match authenticated launch intent.' );
			}
			const executableIdentityAfter = fileIdentitySnapshot( this.executable );
			const ownerHashAfter = await raceStartup( hashFile( this.executable ) );
			const markerAfter = this.readMarkerSnapshot();
			if (
				ownerHashAfter !== this.expectedExecutableSha256 ||
				! sameFileIdentitySnapshot( ownerSnapshot.executableIdentity, executableIdentityAfter ) ||
				! sameMarkerSnapshot( ownerSnapshot.marker, markerAfter )
			) {
				throw new Error( 'Chrome installation changed during supervised startup.' );
			}
			const endpoint = new URL( event.endpoint );
			if ( 'ws:' !== endpoint.protocol || ! [ '127.0.0.1', 'localhost', '::1' ].includes( endpoint.hostname ) ) {
				throw new Error( 'Chrome supervisor exposed a non-loopback CDP endpoint.' );
			}
			state.browserPid = event.browserPid;
			state.endpoint = event.endpoint;
			this.processSnapshots = state.processSnapshots.slice();
			this.browserPid = event.browserPid;
			this.endpoint = event.endpoint;
			state.websocket = await raceStartup( connectWebSocket( event.endpoint ) );
			state.cdp = new CdpClient( state.websocket );
			state.websocket.onClose( ( error ) => {
				if ( ! state.active ) {
					state.rejectStartup( markTransportError( error ) );
				} else if ( this.websocket === state.websocket ) {
					this.healthy = false;
					this.lastTransportError = markTransportError( error );
				}
			} );
			const browserVersion = await raceStartup( state.cdp.send( 'Browser.getVersion' ) );
			if (
				'string' !== typeof browserVersion.product ||
				'' === browserVersion.product ||
				'string' !== typeof browserVersion.protocolVersion ||
				'' === browserVersion.protocolVersion
			) {
				throw new Error( 'Live Chrome returned incomplete browser identity.' );
			}
			const liveVersion = browserVersion.product.replace( /^[^/]+\//, '' );
			if ( liveVersion !== PINNED_CHROME_VERSION ) {
				throw new Error( 'Live Chrome version does not match pin ' + PINNED_CHROME_VERSION + '.' );
			}
			const target = await raceStartup( state.cdp.send( 'Target.createTarget', { url: 'about:blank', background: true } ) );
			state.targetId = target.targetId;
			const attached = await raceStartup( state.cdp.send( 'Target.attachToTarget', { targetId: state.targetId, flatten: true } ) );
			state.sessionId = attached.sessionId;
			await raceStartup( state.cdp.send( 'Page.enable', {}, state.sessionId ) );
			await raceStartup( state.cdp.send( 'Runtime.enable', {}, state.sessionId ) );
			await raceStartup( state.cdp.send( 'Network.enable', {}, state.sessionId ) );
			await raceStartup( state.cdp.send( 'Network.setCacheDisabled', { cacheDisabled: true }, state.sessionId ) );
			await raceStartup( state.cdp.send( 'Network.setBypassServiceWorker', { bypass: true }, state.sessionId ) );
			await raceStartup( state.cdp.send( 'Network.setBlockedURLs', {
				urls: [ 'http://*', 'https://*', 'ftp://*', 'file://*', 'ws://*', 'wss://*', '*://*' ],
			}, state.sessionId ) );
			await raceStartup( state.cdp.send( 'Browser.setDownloadBehavior', { behavior: 'deny' } ) );

			const handshakePauseFile = process.env.HTML_API_FUZZ_CHROME_TEST_PAUSE_AFTER_CDP_HANDSHAKE;
			if ( handshakePauseFile ) {
				fs.writeFileSync(
					handshakePauseFile,
					JSON.stringify( {
						ownerPid: process.pid,
						supervisorPid: state.supervisor.pid,
						browserPid: state.browserPid,
						runtimeRoot: state.runtimeRoot,
						profilePath: state.profilePath,
						socketPath: this.socketPath,
					} ) + '\n',
					{ mode: 0o600 }
				);
				while ( fs.existsSync( handshakePauseFile ) ) {
					await raceStartup( delay( 10 ) );
				}
			}
			const publicationVersion = await raceStartup( state.cdp.send( 'Browser.getVersion' ) );
			if (
				publicationVersion.product !== browserVersion.product ||
				publicationVersion.protocolVersion !== browserVersion.protocolVersion
			) {
				throw new Error( 'Live Chrome identity changed before healthy publication.' );
			}
			await raceStartup( delay( 0 ) );
			if (
				state.supervisorExit ||
				state.browserExit ||
				state.supervisorProtocolFailure ||
				null !== state.supervisor.exitCode ||
				null !== state.supervisor.signalCode ||
				state.websocket.closed
			) {
				throw state.supervisorExit || state.browserExit || state.supervisorProtocolFailure || state.websocket.closeError ||
					transportError( 'Chrome startup ownership ended before healthy publication.' );
			}

			this.websocket = state.websocket;
			this.cdp = state.cdp;
			this.targetId = state.targetId;
			this.sessionId = state.sessionId;
			this.chromeVersion = liveVersion;
			this.durableIdentity.cdpProtocolVersion = browserVersion.protocolVersion;
			this.browserInstance++;
			state.active = true;
			this.lastTransportError = null;
			this.healthy = true;
			startupSettled = true;
		} catch ( error ) {
			state.intentionalExit = true;
			await this.disposeState( state, false ).catch( ( cleanupError ) => {
				error = new AggregateError( [ error, cleanupError ], 'Chrome startup and cleanup both failed.' );
			} );
			throw error.failureClass ? error : markTransportError( error );
		}
	}

	async handleUnexpectedSupervisorExit( code, signal ) {
		return this.handleSupervisorInfrastructureFailure( transportError(
			'Chrome supervisor ownership failed (code ' + code + ', signal ' + signal + ').'
		) );
	}

	async handleSupervisorInfrastructureFailure( failure ) {
		if ( this.fatalInfrastructure || this.closing ) {
			return;
		}
		this.fatalInfrastructure = true;
		this.healthy = false;
		failure.failureClass = 'oracle-infrastructure-failure';
		this.lastTransportError = markTransportError( failure );
		try {
			await this.resetState( false );
		} catch ( error ) {
			const combined = new AggregateError(
				[ this.lastTransportError, error ],
				'Chrome supervisor failure cleanup failed.'
			);
			combined.failureClass = 'oracle-infrastructure-failure';
			this.lastTransportError = markTransportError( combined );
		}
		if ( this.fatalHandler ) {
			try {
				await this.fatalHandler( this.lastTransportError );
			} catch ( error ) {
				const combined = new AggregateError(
					[ this.lastTransportError, error ],
					'Chrome fatal infrastructure shutdown failed.'
				);
				combined.failureClass = 'oracle-infrastructure-failure';
				this.lastTransportError = markTransportError( combined );
			}
		}
	}

	async waitForSupervisorExit( supervisor, timeoutMs ) {
		if ( ! supervisor || null !== supervisor.exitCode || null !== supervisor.signalCode ) {
			return true;
		}
		const outcome = await Promise.race( [
			once( supervisor, 'exit' ).then( () => true ),
			delay( timeoutMs ).then( () => false ),
		] );
		return outcome;
	}

	async disposeState( state, graceful ) {
		const errors = [];
		state.intentionalExit = true;
		if ( state.supervisor ) {
			state.supervisor.__htmlApiFuzzIntentional = true;
		}
		if ( graceful && state.cdp ) {
			if ( state.targetId ) {
				await state.cdp.send( 'Target.closeTarget', { targetId: state.targetId }, undefined, 500 ).catch( () => {} );
			}
			await state.cdp.send( 'Browser.close', {}, undefined, 1000 ).catch( () => {} );
		}
		try {
			state.websocket?.close();
		} catch ( _error ) {}
		if ( state.supervisor?.stdin && ! state.supervisor.stdin.destroyed ) {
			if ( null !== state.supervisor.exitCode || null !== state.supervisor.signalCode ) {
				state.supervisor.stdin.destroy();
			} else {
				state.supervisor.stdin.end( 'controlled-shutdown\n' );
			}
		}
		let supervisorGone = ! state.supervisor || await this.waitForSupervisorExit( state.supervisor, 5000 );
		if ( state.supervisor && ! supervisorGone ) {
			try {
				state.supervisor.kill( 'SIGTERM' );
			} catch ( _error ) {}
			supervisorGone = await this.waitForSupervisorExit( state.supervisor, 1000 );
			if ( ! supervisorGone ) {
				try {
					state.supervisor.kill( 'SIGKILL' );
				} catch ( _error ) {}
				supervisorGone = await this.waitForSupervisorExit( state.supervisor, 1000 );
			}
		}
		if ( ! supervisorGone ) {
			errors.push( new Error( 'Chrome supervisor survived its final SIGKILL deadline.' ) );
		} else {
			try {
				await reapAndRemoveRuntimeResources(
					state.processSnapshots,
					state.profilePath,
					state.ownershipToken,
					state.runtimeRoot,
					''
				);
			} catch ( error ) {
				errors.push( error );
			}
		}
		if ( errors.length ) {
			const failure = 1 === errors.length ? errors[ 0 ] : new AggregateError( errors, 'Chrome state cleanup failed.' );
			failure.failureClass = 'oracle-infrastructure-failure';
			throw markTransportError( failure );
		}
	}

	async resetState( graceful ) {
		const state = {
			supervisor: this.supervisor,
			runtimeRoot: this.runtimeRoot,
			profilePath: this.profilePath,
			ownershipToken: this.ownershipToken,
			processSnapshots: this.processSnapshots,
			browserPid: this.browserPid,
			endpoint: this.endpoint,
			websocket: this.websocket,
			cdp: this.cdp,
			targetId: this.targetId,
			sessionId: this.sessionId,
			intentionalExit: true,
		};
		this.supervisor = null;
		this.runtimeRoot = null;
		this.profilePath = null;
		this.ownershipToken = null;
		this.processSnapshots = [];
		this.browserPid = null;
		this.endpoint = null;
		this.websocket = null;
		this.cdp = null;
		this.targetId = null;
		this.sessionId = null;
		this.healthy = false;
		await this.disposeState( state, graceful );
	}

	validateRequest( request ) {
		if ( ! request || 'object' !== typeof request || Array.isArray( request ) ) {
			throw new Error( 'Request must be a JSON object.' );
		}
		const allowed = new Set( [
			'id', 'command', 'htmlBase64', 'mode', 'context',
			'maxNodes', 'maxDepth', 'maxTreeBytes', 'securityAudit',
		] );
		if ( 'render' !== request.command ) {
			throw new Error( 'Render request command must be render.' );
		}
		if ( undefined !== request.id && ! isValidRequestId( request.id ) ) {
			throw new Error( 'Request id must be a string or safe integer.' );
		}
		for ( const key of Object.keys( request ) ) {
			if ( ! allowed.has( key ) ) {
				throw new Error( 'Unknown request field: ' + key + '.' );
			}
		}
		const mode = undefined === request.mode ? 'fragment-body' : request.mode;
		if ( ! [ 'full-document', 'fragment-body' ].includes( mode ) ) {
			throw new Error( 'Unsupported parse mode: ' + mode + '.' );
		}
		const context = undefined === request.context ? 'body' : request.context;
		if ( 'fragment-body' === mode && ! CONTEXT_SET.has( context ) ) {
			throw new Error( 'Unsupported fragment context: ' + context + '.' );
		}
		if ( 'full-document' === mode && undefined !== request.context ) {
			throw new Error( 'context is only valid for fragment-body mode.' );
		}
		const limits = {};
		for ( const [ key, defaultValue, maximum ] of [
			[ 'maxNodes', 3000, MAX_NODES ],
			[ 'maxDepth', 512, MAX_DEPTH ],
			[ 'maxTreeBytes', MAX_TREE_BYTES, MAX_TREE_BYTES ],
		] ) {
			const value = undefined === request[ key ] ? defaultValue : request[ key ];
			if ( ! Number.isSafeInteger( value ) || value < 1 || value > maximum ) {
				throw new Error( key + ' must be a positive integer no greater than ' + maximum + '.' );
			}
			limits[ key ] = value;
		}
		if ( undefined !== request.securityAudit && 'boolean' !== typeof request.securityAudit ) {
			throw new Error( 'securityAudit must be boolean.' );
		}
		if ( 'string' !== typeof request.htmlBase64 ) {
			throw new Error( 'htmlBase64 must be a string.' );
		}
		const encoded = request.htmlBase64;
		if (
			0 !== encoded.length % 4 ||
			! /^(?:[A-Za-z0-9+/]{4})*(?:[A-Za-z0-9+/]{2}==|[A-Za-z0-9+/]{3}=)?$/.test( encoded )
		) {
			throw new Error( 'htmlBase64 must be canonical base64.' );
		}
		const bytes = Buffer.from( encoded, 'base64' );
		if ( bytes.toString( 'base64' ) !== encoded ) {
			throw new Error( 'htmlBase64 must be canonical base64.' );
		}
		if ( bytes.length > MAX_INPUT_BYTES ) {
			const error = new Error( 'HTML input exceeds 2 MiB.' );
			error.failureClass = 'input-byte-limit-exceeded';
			throw error;
		}
		let html = null;
		let invalidUtf8 = false;
		try {
			html = new TextDecoder( 'utf-8', { fatal: true } ).decode( bytes );
		} catch ( _error ) {
			invalidUtf8 = true;
		}
		return { mode, context, limits, html, invalidUtf8, securityAudit: true === request.securityAudit };
	}

	render( request ) {
		const work = this.renderQueue.then( () => this.renderWithRecovery( request ) );
		this.renderQueue = work.catch( () => {} );
		return work;
	}

	async observeSessionDeath( error, supervisor ) {
		let observed = isRecoverableSessionDeath( error );
		if (
			! observed &&
			supervisor &&
			true === error?.transportFailure &&
			! error.invalidateSession
		) {
			const deadline = Date.now() + 500;
			while (
				Date.now() < deadline &&
				this.supervisor === supervisor &&
				! this.fatalInfrastructure
			) {
				if ( isRecoverableSessionDeath( this.lastTransportError ) ) {
					observed = true;
					break;
				}
				await delay( 10 );
			}
		}
		return observed;
	}

	async resetFailedRenderSession( error, message ) {
		try {
			await this.resetState( false );
		} catch ( cleanupError ) {
			const combined = new AggregateError( [ error, cleanupError ], message );
			combined.failureClass = 'oracle-infrastructure-failure';
			throw markTransportError( combined );
		}
	}

	async renderWithRecovery( request ) {
		const validated = this.validateRequest( request );
		let liveSupervisor = null;
		try {
			await this.start();
			liveSupervisor = this.supervisor;
			const session = {
				supervisor: liveSupervisor,
				cdp: this.cdp,
				sessionId: this.sessionId,
				oracle: this.metadata(),
			};
			return await this.renderNow( validated, session );
		} catch ( error ) {
			const observedSessionDeath = await this.observeSessionDeath( error, liveSupervisor );
			if ( ! observedSessionDeath ) {
				if (
					liveSupervisor &&
					this.supervisor === liveSupervisor &&
					! this.closing &&
					! this.fatalInfrastructure &&
					true === error?.invalidateSession
				) {
					await this.resetFailedRenderSession(
						error,
						'Chrome request failure and session teardown both failed.'
					);
				}
				throw error;
			}
			if ( ! liveSupervisor || this.closing || this.fatalInfrastructure ) {
				throw error;
			}
			await this.resetFailedRenderSession(
				error,
				'Chrome session death and cleanup both failed.'
			);
			let retrySupervisor = null;
			try {
				await this.start();
				retrySupervisor = this.supervisor;
				const retrySession = {
					supervisor: retrySupervisor,
					cdp: this.cdp,
					sessionId: this.sessionId,
					oracle: this.metadata(),
				};
				return await this.renderNow( validated, retrySession );
			} catch ( retryError ) {
				const retrySessionDeath = await this.observeSessionDeath( retryError, retrySupervisor );
				if (
					retrySupervisor &&
					this.supervisor === retrySupervisor &&
					! this.closing &&
					! this.fatalInfrastructure &&
					( true === retryError?.invalidateSession || retrySessionDeath )
				) {
					await this.resetFailedRenderSession(
						retryError,
						'Final Chrome retry failure and session teardown both failed.'
					);
				}
				throw retryError;
			}
		}
	}

	async renderNow( request, session ) {
		if ( ! session?.supervisor || ! session.cdp || ! session.sessionId ) {
			throw this.lastTransportError || transportError( 'Chrome render session was unavailable after startup.' );
		}
		if ( request.invalidUtf8 ) {
			return {
				status: 'unsupported',
				failureClass: 'invalid-utf8',
				unsupported: { reason: 'invalid-utf8' },
				oracle: session.oracle,
			};
		}
		const expression = '(' + browserRender.toString() + ')(' + JSON.stringify( {
			html: request.html,
			mode: request.mode,
			context: request.context,
			maxNodes: request.limits.maxNodes,
			maxDepth: request.limits.maxDepth,
			maxTreeBytes: request.limits.maxTreeBytes,
			...( request.testProcessingInstruction
				? { testProcessingInstruction: request.testProcessingInstruction }
				: {} ),
		} ) + ')';
		const evaluated = await session.cdp.send( 'Runtime.evaluate', {
			expression,
			returnByValue: true,
			awaitPromise: false,
			userGesture: false,
		}, session.sessionId, 10000 );
		if ( Object.hasOwn( evaluated, 'exceptionDetails' ) ) {
			throw cdpProtocolError(
				evaluated.exceptionDetails?.exception?.description ||
				evaluated.exceptionDetails?.text ||
				'Chrome renderer evaluation escaped its structured result.'
			);
		}
		const rendered = evaluated.result?.value;
		const renderedKeys = 'ok' === rendered?.status
			? [ 'status', 'treeBase64', 'treeBytes', 'nodeCount' ]
			: [ 'status', 'failureClass', 'error', 'nodeCount', 'treeBytes' ];
		if (
			! hasExactOwnKeys( rendered, renderedKeys ) ||
			! [ 'ok', 'limit', 'error' ].includes( rendered.status ) ||
			! Number.isSafeInteger( rendered.nodeCount ) ||
			rendered.nodeCount < 0 ||
			rendered.nodeCount > request.limits.maxNodes + 1 ||
			! Number.isSafeInteger( rendered.treeBytes ) ||
			rendered.treeBytes < 0 ||
			rendered.treeBytes > request.limits.maxTreeBytes
		) {
			throw cdpProtocolError( 'Chrome returned an invalid renderer result envelope.' );
		}
		if ( 'ok' !== rendered.status ) {
			const isNodeLimit = 'limit' === rendered.status && 'node-limit-exceeded' === rendered.failureClass;
			const validFailureClass = 'limit' === rendered.status
				? [ 'node-limit-exceeded', 'depth-limit-exceeded', 'tree-byte-limit-exceeded' ].includes( rendered.failureClass )
				: 'oracle-renderer-error' === rendered.failureClass;
			if (
				! validFailureClass ||
				'string' !== typeof rendered.error ||
				'' === rendered.error ||
				Buffer.byteLength( rendered.error, 'utf8' ) > 65536 ||
				( isNodeLimit
					? rendered.nodeCount !== request.limits.maxNodes + 1
					: rendered.nodeCount > request.limits.maxNodes )
			) {
				throw cdpProtocolError( 'Chrome returned an invalid non-success renderer result.' );
			}
			return {
				status: rendered.status,
				failureClass: rendered.failureClass,
				error: rendered.error,
				nodeCount: rendered.nodeCount,
				treeBytes: rendered.treeBytes,
				oracle: session.oracle,
			};
		}
		if (
			rendered.nodeCount > request.limits.maxNodes ||
			'string' !== typeof rendered.treeBase64
		) {
			throw cdpProtocolError( 'Chrome did not return treeBase64.' );
		}
		const tree = Buffer.from( rendered.treeBase64, 'base64' );
		if (
			tree.toString( 'base64' ) !== rendered.treeBase64 ||
			tree.length !== rendered.treeBytes ||
			tree.length > request.limits.maxTreeBytes
		) {
			throw cdpProtocolError( 'Chrome returned inconsistent canonical tree bytes.' );
		}
		let canonicalTree;
		try {
			canonicalTree = JSON_FRAME_DECODER.decode( tree );
		} catch ( error ) {
			throw cdpProtocolError( 'Chrome returned non-UTF-8 canonical tree bytes.', error );
		}
		if ( ! Buffer.from( canonicalTree, 'utf8' ).equals( tree ) ) {
			throw cdpProtocolError( 'Chrome returned noncanonical UTF-8 tree text.' );
		}
		const result = {
			status: 'ok',
			oracle: session.oracle,
			treeBase64: rendered.treeBase64,
			treeBytes: tree.length,
			treeSha256: crypto.createHash( 'sha256' ).update( tree ).digest( 'hex' ),
			nodeCount: rendered.nodeCount,
		};
		if ( request.securityAudit ) {
			const audited = await session.cdp.send( 'Runtime.evaluate', {
				expression: '({authorRan:Boolean(globalThis.__htmlApiFuzzAuthorRan),' +
					'activeMarkup:document.documentElement.outerHTML,' +
					'resources:performance.getEntriesByType("resource").map((entry)=>entry.name)})',
				returnByValue: true,
				awaitPromise: false,
				userGesture: false,
			}, session.sessionId, 2000 );
			const audit = audited.result?.value;
			if (
				audited.exceptionDetails ||
				! hasExactOwnKeys( audit, [ 'authorRan', 'activeMarkup', 'resources' ] ) ||
				'boolean' !== typeof audit.authorRan ||
				'string' !== typeof audit.activeMarkup ||
				! Array.isArray( audit.resources ) ||
				audit.resources.some( ( resource ) => 'string' !== typeof resource )
			) {
				throw cdpProtocolError( 'Chrome returned an invalid security-audit result.' );
			}
			result.securityAudit = audit;
		}
		return result;
	}

	async killBrowserForTest() {
		await this.start();
		const records = this.processSnapshots.at( -1 ) || [];
		const root = records.find( ( record ) => record.pid === this.browserPid );
		if ( ! root || ! sameProcessIdentity( root, currentIdentityForPid( root.pid ) ) ) {
			throw new Error( 'Could not authenticate browser process for test termination.' );
		}
		process.kill( root.pid, 'SIGKILL' );
		return { status: 'ok', killedBrowserPid: root.pid, oracle: this.metadata() };
	}

	async renderProcessingInstructionForTest() {
		await this.start();
		const session = {
			supervisor: this.supervisor,
			cdp: this.cdp,
			sessionId: this.sessionId,
			oracle: this.metadata(),
		};
		return this.renderNow( {
			html: '',
			mode: 'full-document',
			context: 'body',
			limits: { maxNodes: 10, maxDepth: 10, maxTreeBytes: 1024 },
			invalidUtf8: false,
			securityAudit: false,
			testProcessingInstruction: { target: 'pi', data: '' },
		}, session );
	}

	close() {
		if ( this.closePromise ) {
			return this.closePromise;
		}
		this.closing = true;
		this.closePromise = ( async () => {
			await this.renderQueue.catch( () => {} );
			await this.startPromise?.catch( () => {} );
			await this.resetState( true );
		} )();
		return this.closePromise;
	}
}

function protocolError( message ) {
	const error = new Error( message );
	error.failureClass = 'protocol-error';
	return error;
}

function errorResult( error, oracle, id ) {
	const message = error?.message || String( error );
	let status = 'error';
	let failureClass = error?.failureClass;
	if ( failureClass?.endsWith( '-limit-exceeded' ) ) {
		status = 'limit';
	}
	if ( ! failureClass ) {
		failureClass = error?.transportFailure ? 'oracle-infrastructure-failure' : 'oracle-renderer-error';
	}
	const result = {
		status,
		failureClass,
		error: message,
		oracle,
	};
	if ( undefined !== id ) {
		result.id = id;
	}
	return result;
}

async function writeJsonFrame( stream, result, drainTimeoutMs = SOCKET_WRITE_TIMEOUT_MS ) {
	if ( TERMINAL_OUTPUT_STREAMS.has( stream ) ) {
		const error = new Error( 'Oracle response stream is terminally unavailable.' );
		error.code = 'HTML_API_FUZZ_OUTPUT_TIMEOUT';
		throw error;
	}
	if ( false === stream.writable || stream.destroyed ) {
		return;
	}
	const frame = Buffer.from( JSON.stringify( result ) + '\n', 'utf8' );
	if ( frame.length > MAX_RESPONSE_FRAME_BYTES ) {
		throw new Error( 'Oracle response frame exceeded 24 MiB.' );
	}
	if ( ! stream.write( frame ) ) {
		if ( drainTimeoutMs <= 0 ) {
			await once( stream, 'drain' );
			return;
		}
		await new Promise( ( resolve, reject ) => {
			let settled = false;
			const finish = ( error = null ) => {
				if ( settled ) {
					return;
				}
				settled = true;
				clearTimeout( timer );
				stream.removeListener( 'drain', onDrain );
				stream.removeListener( 'close', onClose );
				stream.removeListener( 'error', onError );
				error ? reject( error ) : resolve();
			};
			const onDrain = () => finish();
			const onClose = () => {
				const error = new Error( 'Oracle response stream closed before draining.' );
				error.code = 'HTML_API_FUZZ_OUTPUT_CLOSED';
				finish( error );
			};
			const onError = ( error ) => finish( error );
			const timer = setTimeout( () => {
				const error = new Error( 'Oracle response drain timed out.' );
				error.code = 'HTML_API_FUZZ_OUTPUT_TIMEOUT';
				TERMINAL_OUTPUT_STREAMS.add( stream );
				if ( stream === process.stdout ) {
					forceProcessExitAfterCleanup = true;
					process.exitCode ||= 1;
				}
				finish( error );
				if (
					stream !== process.stdout &&
					! stream.destroyed &&
					'function' === typeof stream.destroy
				) {
					stream.destroy();
				}
			}, drainTimeoutMs );
			timer.unref();
			stream.once( 'drain', onDrain );
			stream.once( 'close', onClose );
			stream.once( 'error', onError );
		} );
	}
}

function assertCommandFields( request, allowed ) {
	if ( ! request || 'object' !== typeof request || Array.isArray( request ) ) {
		throw protocolError( 'Request must be a JSON object.' );
	}
	if ( undefined !== request.id && ! isValidRequestId( request.id ) ) {
		throw protocolError( 'Request id must be a string or safe integer.' );
	}
	for ( const key of Object.keys( request ) ) {
		if ( ! allowed.has( key ) ) {
			throw protocolError( 'Unknown request field: ' + key + '.' );
		}
	}
}

async function dispatchRequest( oracle, request ) {
	if ( ! request || 'object' !== typeof request || Array.isArray( request ) ) {
		throw protocolError( 'Request must be a JSON object.' );
	}
	if ( 'string' !== typeof request.command ) {
		throw protocolError( 'command must be a string.' );
	}
	if ( 'version' === request.command ) {
		assertCommandFields( request, new Set( [ 'id', 'command' ] ) );
		await oracle.start();
		return { status: 'ok', oracle: oracle.metadata() };
	}
	if ( 'shutdown' === request.command ) {
		assertCommandFields( request, new Set( [ 'id', 'command' ] ) );
		return { status: 'ok', oracle: oracle.metadata(), shutdown: true };
	}
	if (
		'test-kill-browser' === request.command &&
		'1' === process.env[ TEST_ALLOW_INTERNAL_COMMANDS_ENV ]
	) {
		assertCommandFields( request, new Set( [ 'id', 'command' ] ) );
		return oracle.killBrowserForTest();
	}
	if (
		'test-render-processing-instruction' === request.command &&
		'1' === process.env[ TEST_ALLOW_INTERNAL_COMMANDS_ENV ]
	) {
		assertCommandFields( request, new Set( [ 'id', 'command' ] ) );
		return oracle.renderProcessingInstructionForTest();
	}
	if (
		'test-large-response' === request.command &&
		'1' === process.env[ TEST_ALLOW_INTERNAL_COMMANDS_ENV ]
	) {
		assertCommandFields( request, new Set( [ 'id', 'command' ] ) );
		return { status: 'ok', testPayload: 'x'.repeat( 20 * 1024 * 1024 ), oracle: oracle.metadata() };
	}
	if ( 'render' !== request.command ) {
		throw protocolError( 'Unknown command: ' + request.command + '.' );
	}
	try {
		return await oracle.render( request );
	} catch ( error ) {
		if (
			! error.failureClass &&
			/^(?:Request|Render request|Unknown request field|Unsupported parse mode|Unsupported fragment context|context |maxNodes |maxDepth |maxTreeBytes |securityAudit |htmlBase64 )/.test( error.message )
		) {
			error.failureClass = 'protocol-error';
		}
		throw error;
	}
}

class FramePeer {
	constructor( input, output, oracle, globalDispatch, options = {} ) {
		this.input = input;
		this.output = output;
		this.oracle = oracle;
		this.globalDispatch = globalDispatch;
		this.closeOnOversize = true === options.closeOnOversize;
		this.singleFrame = true === options.singleFrame;
		this.cancelOnDisconnect = true === options.cancelOnDisconnect;
		this.writeTimeoutMs = options.writeTimeoutMs ?? SOCKET_WRITE_TIMEOUT_MS;
		this.onShutdown = options.onShutdown || ( async () => {} );
		this.buffer = Buffer.alloc( 0 );
		this.discarding = false;
		this.oversizeReported = false;
		this.closed = false;
		this.processing = Promise.resolve();
		this.resolveClosed = null;
		this.closedPromise = new Promise( ( resolve ) => {
			this.resolveClosed = resolve;
		} );
		this.readTimer = options.readTimeoutMs > 0
			? setTimeout( () => this.finish(), options.readTimeoutMs )
			: null;
		this.readTimer?.unref();
		this.input.on( 'data', ( chunk ) => this.receive( chunk ) );
		this.input.once( 'end', () => this.finish() );
		this.input.once( 'close', () => this.finish() );
		this.input.once( 'error', () => this.finish() );
	}

	receive( chunk ) {
		if ( this.closed ) {
			return;
		}
		this.input.pause();
		this.processing = this.processing
			.then( () => this.consume( Buffer.from( chunk ) ) )
			.catch( async ( error ) => {
				await this.safeWrite( errorResult( error, this.oracle.metadata() ) );
				this.finish();
			} )
			.finally( () => {
				if ( ! this.closed && ! this.input.destroyed ) {
					this.input.resume();
				}
			} );
	}

	async reportOversize() {
		if ( this.oversizeReported ) {
			return;
		}
		this.oversizeReported = true;
		await this.safeWrite(
			errorResult(
				protocolError( 'Request frame exceeded 4 MiB.' ),
				this.oracle.metadata()
			)
		);
	}

	async consume( chunk ) {
		let offset = 0;
		while ( offset < chunk.length ) {
			if ( this.discarding ) {
				const newline = chunk.indexOf( 0x0a, offset );
				if ( newline < 0 ) {
					return;
				}
				offset = newline + 1;
				this.discarding = false;
				this.oversizeReported = false;
				this.buffer = Buffer.alloc( 0 );
				continue;
			}
			const newline = chunk.indexOf( 0x0a, offset );
			const end = newline < 0 ? chunk.length : newline;
			const slice = chunk.subarray( offset, end );
			if ( this.buffer.length + slice.length > MAX_REQUEST_FRAME_BYTES ) {
				this.buffer = Buffer.alloc( 0 );
				await this.reportOversize();
				if ( this.closeOnOversize ) {
					this.finish();
					return;
				}
				if ( newline < 0 ) {
					this.discarding = true;
					return;
				}
				offset = newline + 1;
				this.oversizeReported = false;
				continue;
			}
			this.buffer = Buffer.concat( [ this.buffer, slice ] );
			if ( newline < 0 ) {
				return;
			}
			const frame = this.buffer;
			this.buffer = Buffer.alloc( 0 );
			offset = newline + 1;
			await this.handleFrame( frame );
			if ( this.closed ) {
				return;
			}
		}
	}

	async handleFrame( frame ) {
		clearTimeout( this.readTimer );
		this.readTimer = null;
		let request;
		try {
			request = JSON.parse( JSON_FRAME_DECODER.decode( frame ) );
		} catch ( error ) {
			error.failureClass = 'protocol-error';
			await this.safeWrite( errorResult( error, this.oracle.metadata() ) );
			if ( this.singleFrame ) {
				this.finishAfterResponse();
			}
			return;
		}
		const id = request && 'object' === typeof request && isValidRequestId( request.id )
			? request.id
			: undefined;
		try {
			const result = await this.globalDispatch( () => {
				if ( this.cancelOnDisconnect && this.closed ) {
					const error = new Error( 'Socket client disconnected before dispatch.' );
					error.clientDisconnected = true;
					throw error;
				}
				return dispatchRequest( this.oracle, request );
			} );
			if ( undefined !== id ) {
				result.id = id;
			}
			await this.safeWrite( result );
			if ( result.shutdown ) {
				await this.onShutdown();
			} else if ( this.singleFrame ) {
				this.finishAfterResponse();
			}
		} catch ( error ) {
			if ( error.clientDisconnected ) {
				return;
			}
			await this.safeWrite( errorResult( error, this.oracle.metadata(), id ) );
			if ( this.singleFrame ) {
				this.finishAfterResponse();
			}
		}
	}

	async safeWrite( result ) {
		try {
			await writeJsonFrame( this.output, result, this.writeTimeoutMs );
		} catch ( error ) {
			if ( [
				'ERR_STREAM_DESTROYED', 'ERR_STREAM_WRITE_AFTER_END', 'EPIPE',
				'HTML_API_FUZZ_OUTPUT_CLOSED', 'HTML_API_FUZZ_OUTPUT_TIMEOUT',
			].includes( error.code ) ) {
				this.finish();
			} else {
				throw error;
			}
		}
	}

	finish() {
		if ( this.closed ) {
			return;
		}
		this.closed = true;
		clearTimeout( this.readTimer );
		this.readTimer = null;
		this.input.pause();
		if ( this.closeOnOversize && ! this.input.destroyed ) {
			this.input.destroy();
		}
		this.resolveClosed();
	}

	finishAfterResponse() {
		if ( this.closed ) {
			return;
		}
		this.closed = true;
		clearTimeout( this.readTimer );
		this.readTimer = null;
		this.input.pause();
		if ( ! this.output.destroyed ) {
			this.output.end();
		}
		this.resolveClosed();
	}
}

function serializedDispatcher() {
	let queue = Promise.resolve();
	return ( callback ) => {
		const result = queue.then( callback );
		queue = result.catch( () => {} );
		return result;
	};
}

async function runStdioServer( oracle, registerShutdown ) {
	let shuttingDown = false;
	let fatalError = null;
	let resolveStop;
	const stopped = new Promise( ( resolve ) => {
		resolveStop = resolve;
	} );
	const shutdown = async ( error = null ) => {
		if ( shuttingDown ) {
			return;
		}
		shuttingDown = true;
		fatalError = error;
		peer.finish();
		process.stdin.destroy();
		resolveStop();
	};
	const peer = new FramePeer(
		process.stdin,
		process.stdout,
		oracle,
		serializedDispatcher(),
		{ closeOnOversize: false, onShutdown: shutdown }
	);
	registerShutdown( shutdown );
	oracle.setFatalHandler( shutdown );
	peer.closedPromise.then( () => shutdown() );
	process.stdin.resume();
	await stopped;
	await peer.processing.catch( () => {} );
	await oracle.close().catch( ( error ) => {
		fatalError ||= error;
	} );
	if ( fatalError ) {
		throw fatalError;
	}
}

function assertSecureSocketPath( socketPath ) {
	if ( ! path.isAbsolute( socketPath ) ) {
		throw new Error( '--socket must be an absolute path.' );
	}
	const parent = path.dirname( socketPath );
	const parentStat = fs.lstatSync( parent );
	if (
		! parentStat.isDirectory() ||
		parentStat.isSymbolicLink() ||
		( 'function' === typeof process.getuid && parentStat.uid !== process.getuid() ) ||
		0o700 !== ( parentStat.mode & 0o777 )
	) {
		throw new Error( 'Socket parent must be a nonsymlink directory owned by the current uid with mode 0700.' );
	}
	if ( fs.existsSync( socketPath ) ) {
		throw new Error( 'Refusing pre-existing socket path: ' + socketPath );
	}
}

async function runSocketServer( oracle, socketPath, registerShutdown ) {
	assertSecureSocketPath( socketPath );
	let shuttingDown = false;
	let fatalError = null;
	let resolveStopped;
	const stopped = new Promise( ( resolve ) => {
		resolveStopped = resolve;
	} );
	const peers = new Set();
	const dispatch = serializedDispatcher();
	const server = net.createServer( ( socket ) => {
		if ( peers.size >= MAX_SOCKET_CLIENTS ) {
			writeJsonFrame(
				socket,
				errorResult( protocolError( 'Socket client limit exceeded.' ), oracle.metadata() ),
				SOCKET_WRITE_TIMEOUT_MS
			).catch( () => {} ).finally( () => socket.destroy() );
			return;
		}
		const peer = new FramePeer(
			socket,
			socket,
			oracle,
			dispatch,
			{
				closeOnOversize: true,
				singleFrame: true,
				cancelOnDisconnect: true,
				readTimeoutMs: SOCKET_FRAME_TIMEOUT_MS,
				writeTimeoutMs: SOCKET_WRITE_TIMEOUT_MS,
				onShutdown: shutdown,
			}
		);
		peers.add( peer );
		peer.closedPromise.then( () => peer.processing.catch( () => {} ) ).then( () => peers.delete( peer ) );
	} );
	const shutdown = async ( error = null ) => {
		if ( shuttingDown ) {
			return;
		}
		shuttingDown = true;
		fatalError = error;
		await new Promise( ( resolve ) => {
			try {
				server.close( () => resolve() );
			} catch ( _error ) {
				resolve();
			}
			setTimeout( resolve, 1000 ).unref();
		} );
		for ( const peer of peers ) {
			peer.finish();
			if ( ! peer.output.destroyed ) {
				peer.output.end();
			}
		}
		await oracle.close().catch( ( closeError ) => {
			fatalError ||= closeError;
		} );
		try {
			fs.unlinkSync( socketPath );
		} catch ( unlinkError ) {
			if ( 'ENOENT' !== unlinkError.code ) {
				fatalError ||= unlinkError;
			}
		}
		resolveStopped();
	};
	registerShutdown( shutdown );
	oracle.setFatalHandler( shutdown );
	server.on( 'error', ( error ) => shutdown( error ) );
	try {
		await oracle.start();
	} catch ( error ) {
		await shutdown( error );
		throw error;
	}
	if ( shuttingDown ) {
		await stopped;
		if ( fatalError ) {
			throw fatalError;
		}
		return;
	}
	assertSecureSocketPath( socketPath );
	const previousUmask = process.umask( 0o177 );
	try {
		try {
			await new Promise( ( resolve, reject ) => {
				server.once( 'error', reject );
				server.listen( socketPath, resolve );
			} );
		} finally {
			process.umask( previousUmask );
		}
		fs.chmodSync( socketPath, 0o600 );
	} catch ( error ) {
		await shutdown( error );
		throw error;
	}
	try {
		await writeJsonFrame( process.stdout, {
			status: 'ready',
			oracle: oracle.metadata(),
		} );
	} catch ( error ) {
		await shutdown( error );
		throw error;
	}
	await stopped;
	if ( fatalError ) {
		throw fatalError;
	}
}

function printUsage() {
	process.stdout.write(
		'Usage: chrome-tree-oracle.js --engine chrome --mode full-document|fragment-body --input PATH ' +
		'[--context TAG] [--max-nodes N] [--max-depth N] [--max-tree-bytes N] [--chrome-executable PATH]\n' +
		'       chrome-tree-oracle.js --serve [--socket ABSOLUTE_PATH] [--engine chrome] [--chrome-executable PATH]\n' +
		'       chrome-tree-oracle.js --version [--engine chrome] [--chrome-executable PATH]\n'
	);
}

async function main() {
	if ( '--internal-supervisor' === process.argv[ 2 ] ) {
		try {
			const internal = parseInternalSupervisorArgs( process.argv );
			await runInternalSupervisor( internal );
		} catch ( error ) {
			process.stderr.write( 'Chrome internal supervisor failed: ' + ( error.stack || error.message || error ) + '\n' );
			process.exitCode = 1;
		}
		return;
	}

	let options;
	try {
		options = parseArgs( process.argv );
	} catch ( error ) {
		await writeJsonFrame(
			process.stdout,
			errorResult( protocolError( error.message ), { kind: 'chrome-cdp', available: false } )
		);
		process.exitCode = 1;
		return;
	}
	if ( options.help ) {
		printUsage();
		return;
	}

	const socketPath = options.socket ? path.resolve( options.socket ) : '';
	let oracle;
	try {
		oracle = new ChromeOracle( options, socketPath );
	} catch ( error ) {
		await writeJsonFrame(
			process.stdout,
			errorResult( error, { kind: 'chrome-cdp', available: false } )
		);
		process.exitCode = 1;
		return;
	}

	let signalShutdown = null;
	let stopping = false;
	const stopForSignal = async ( signal ) => {
		if ( stopping ) {
			return;
		}
		stopping = true;
		try {
			if ( signalShutdown ) {
				await signalShutdown();
			} else {
				await oracle.close();
			}
			process.exitCode = 0;
		} catch ( error ) {
			process.stderr.write( 'Chrome cleanup failed after ' + signal + ': ' + ( error.stack || error.message || error ) + '\n' );
			process.exitCode = 1;
		}
	};
	process.once( 'SIGINT', () => stopForSignal( 'SIGINT' ) );
	process.once( 'SIGTERM', () => stopForSignal( 'SIGTERM' ) );

	try {
		if ( options.serve ) {
			if ( socketPath ) {
				await runSocketServer( oracle, socketPath, ( shutdown ) => {
					signalShutdown = shutdown;
				} );
			} else {
				await runStdioServer( oracle, ( shutdown ) => {
					signalShutdown = shutdown;
				} );
			}
			return;
		}
		if ( options.version ) {
			await oracle.start();
			await writeJsonFrame( process.stdout, { status: 'ok', oracle: oracle.metadata() } );
			return;
		}
		const html = await readBoundedRegularFile( options.input, MAX_INPUT_BYTES );
		const result = await oracle.render( {
			command: 'render',
			htmlBase64: html.toString( 'base64' ),
			mode: options.mode,
			...( options.context ? { context: options.context } : {} ),
			maxNodes: Number( options[ 'max-nodes' ] || 3000 ),
			maxDepth: Number( options[ 'max-depth' ] || 512 ),
			maxTreeBytes: Number( options[ 'max-tree-bytes' ] || MAX_TREE_BYTES ),
		} );
		await writeJsonFrame( process.stdout, result );
	} catch ( error ) {
		await writeJsonFrame( process.stdout, errorResult( error, oracle.metadata() ) );
		process.exitCode = 2;
	} finally {
		await oracle.close().catch( ( error ) => {
			process.stderr.write( 'Chrome final cleanup failed: ' + ( error.stack || error.message || error ) + '\n' );
			process.exitCode = 1;
		} );
	}
}

if ( require.main === module ) {
	main().catch( async ( error ) => {
		try {
			await writeJsonFrame(
				process.stdout,
				errorResult( error, { kind: 'chrome-cdp', available: false } )
			);
		} catch ( _writeError ) {}
		process.exitCode = 1;
	} ).finally( () => {
		if ( forceProcessExitAfterCleanup ) {
			process.exit( process.exitCode || 1 );
		}
	} );
} else {
	module.exports = {
		CdpClient,
		CdpWebSocket,
		ChromeOracle,
		FramePeer,
		createSupervisorEmitter,
		manifestDigest,
		processIdentity,
		readProcessTable,
		reapAndRemoveRuntimeResources,
		reapAuthenticatedTree,
		retainProcessSnapshot,
		sameProcessIdentity,
		serializedDispatcher,
		writeJsonFrame,
	};
}
