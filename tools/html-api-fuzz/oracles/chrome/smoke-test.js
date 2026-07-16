#!/usr/bin/env node
'use strict';

const crypto = require( 'node:crypto' );
const fs = require( 'node:fs' );
const http = require( 'node:http' );
const net = require( 'node:net' );
const os = require( 'node:os' );
const path = require( 'node:path' );
const { once } = require( 'node:events' );
const { EventEmitter } = require( 'node:events' );
const { spawn, spawnSync } = require( 'node:child_process' );

const SCRIPT = path.join( __dirname, 'chrome-tree-oracle.js' );
const INSTALLER = path.join( __dirname, 'install.sh' );
const CONTEXT_FILE = path.join( __dirname, '..', 'fragment-contexts.json' );
const MAX_REQUEST_FRAME_BYTES = 4 * 1024 * 1024;
const MAX_RESPONSE_FRAME_BYTES = 24 * 1024 * 1024;
const MAX_TREE_BYTES = 16 * 1024 * 1024;
const MAX_INPUT_BYTES = 2 * 1024 * 1024;

function assert( condition, message ) {
	if ( ! condition ) {
		throw new Error( message );
	}
}

function delay( milliseconds ) {
	return new Promise( ( resolve ) => setTimeout( resolve, milliseconds ) );
}

function hashFile( filename ) {
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

function executablePath() {
	const checked = spawnSync( INSTALLER, [ '--print-path' ], { encoding: 'utf8' } );
	assert( 0 === checked.status, 'Could not resolve installed Chrome path.' );
	return checked.stdout.trim();
}

function decodeTree( result ) {
	assert( 'ok' === result.status, 'Expected successful render; got ' + JSON.stringify( result ).slice( 0, 1000 ) );
	assert( 'string' === typeof result.treeBase64, 'Successful render omitted treeBase64.' );
	assert( ! Object.hasOwn( result, 'tree' ), 'Successful render duplicated the canonical tree as text.' );
	const tree = Buffer.from( result.treeBase64, 'base64' );
	assert( tree.toString( 'base64' ) === result.treeBase64, 'treeBase64 was not canonical.' );
	assert( tree.length === result.treeBytes, 'treeBytes did not match decoded bytes.' );
	assert(
		hashFileBuffer( tree ) === result.treeSha256,
		'treeSha256 did not match decoded bytes.'
	);
	return tree.toString( 'utf8' );
}

function hashFileBuffer( buffer ) {
	return crypto.createHash( 'sha256' ).update( buffer ).digest( 'hex' );
}

function waitForReady( child, timeoutMs = 30000 ) {
	return new Promise( ( resolve, reject ) => {
		let stdout = Buffer.alloc( 0 );
		let stderr = '';
		let settled = false;
		const finish = ( error, value ) => {
			if ( settled ) {
				return;
			}
			settled = true;
			clearTimeout( timer );
			error ? reject( error ) : resolve( value );
		};
		const timer = setTimeout(
			() => finish( new Error( 'Oracle did not become ready. ' + stderr ) ),
			timeoutMs
		);
		child.stdout.on( 'data', ( chunk ) => {
			if ( stdout.length + chunk.length > 1024 * 1024 ) {
				finish( new Error( 'Oracle ready frame exceeded 1 MiB.' ) );
				return;
			}
			stdout = Buffer.concat( [ stdout, chunk ] );
			const newline = stdout.indexOf( 0x0a );
			if ( newline >= 0 ) {
				try {
					finish( null, JSON.parse( stdout.subarray( 0, newline ).toString( 'utf8' ) ) );
				} catch ( error ) {
					finish( error );
				}
			}
		} );
		child.stderr.on( 'data', ( chunk ) => {
			stderr = ( stderr + chunk.toString( 'utf8' ) ).slice( -65536 );
		} );
		child.once( 'exit', ( code, signal ) => {
			finish( new Error( 'Oracle exited before ready: code=' + code + ' signal=' + signal + '. ' + stderr ) );
		} );
		if ( null !== child.exitCode || null !== child.signalCode ) {
			finish( new Error( 'Oracle exited before ready: code=' + child.exitCode + ' signal=' + child.signalCode + '. ' + stderr ) );
		}
	} );
}

function requestRaw( socketPath, frame, timeoutMs = 30000 ) {
	return new Promise( ( resolve, reject ) => {
		const socket = net.createConnection( socketPath );
		const chunks = [];
		let bytes = 0;
		let settled = false;
		const finish = ( error, value ) => {
			if ( settled ) {
				return;
			}
			settled = true;
			clearTimeout( timer );
			socket.destroy();
			error ? reject( error ) : resolve( value );
		};
		const timer = setTimeout( () => finish( new Error( 'Socket request timed out.' ) ), timeoutMs );
		socket.once( 'error', ( error ) => finish( error ) );
		socket.on( 'data', ( chunk ) => {
			bytes += chunk.length;
			if ( bytes > MAX_RESPONSE_FRAME_BYTES ) {
				finish( new Error( 'Response exceeded 24 MiB.' ) );
				return;
			}
			chunks.push( chunk );
			const combined = Buffer.concat( chunks );
			const newline = combined.indexOf( 0x0a );
			if ( newline >= 0 ) {
				try {
					finish( null, JSON.parse( combined.subarray( 0, newline ).toString( 'utf8' ) ) );
				} catch ( error ) {
					finish( error );
				}
			}
		} );
		socket.once( 'connect', () => socket.write( frame ) );
	} );
}

function request( socketPath, payload, timeoutMs ) {
	return requestRaw( socketPath, Buffer.from( JSON.stringify( payload ) + '\n' ), timeoutMs );
}

async function waitForExit( child, timeoutMs = 15000 ) {
	if ( null !== child.exitCode || null !== child.signalCode ) {
		return { code: child.exitCode, signal: child.signalCode };
	}
	return new Promise( ( resolve, reject ) => {
		let settled = false;
		const finish = ( error, value ) => {
			if ( settled ) {
				return;
			}
			settled = true;
			clearTimeout( timer );
			child.removeListener( 'exit', onExit );
			error ? reject( error ) : resolve( value );
		};
		const onExit = ( code, signal ) => finish( null, { code, signal } );
		const timer = setTimeout(
			() => finish( new Error( 'Process ' + child.pid + ' did not exit.' ) ),
			timeoutMs
		);
		child.once( 'exit', onExit );
		if ( null !== child.exitCode || null !== child.signalCode ) {
			finish( null, { code: child.exitCode, signal: child.signalCode } );
		}
	} );
}

async function waitForPidGone( pid, timeoutMs = 10000 ) {
	if ( ! Number.isSafeInteger( pid ) || pid < 2 ) {
		return;
	}
	const deadline = Date.now() + timeoutMs;
	while ( Date.now() < deadline ) {
		try {
			process.kill( pid, 0 );
		} catch ( error ) {
			if ( 'ESRCH' === error.code ) {
				return;
			}
			if ( 'EPERM' !== error.code ) {
				throw error;
			}
		}
		await delay( 25 );
	}
	throw new Error( 'PID ' + pid + ' survived cleanup.' );
}

async function waitForPathGone( filename, timeoutMs = 10000 ) {
	const deadline = Date.now() + timeoutMs;
	while ( Date.now() < deadline ) {
		if ( ! fs.existsSync( filename ) ) {
			return;
		}
		await delay( 25 );
	}
	throw new Error( filename + ' survived cleanup.' );
}

function processCommandsContaining( needle ) {
	const checked = spawnSync(
		'ps',
		[ '-axww', '-o', 'pid=,command=' ],
		{ encoding: 'utf8', timeout: 3000, maxBuffer: 4 * 1024 * 1024 }
	);
	if ( checked.error || 0 !== checked.status ) {
		throw new Error( 'Could not inspect Chrome processes during smoke test.' );
	}
	return checked.stdout.split( '\n' ).filter( ( line ) => line.includes( needle ) );
}

function captureDescendantIdentities( rootPid ) {
	const { processIdentity, readProcessTable } = require( SCRIPT );
	const table = readProcessTable();
	const byPid = new Map( table.map( ( record ) => [ record.pid, record ] ) );
	assert( byPid.has( rootPid ), 'Could not capture process-tree root ' + rootPid + '.' );
	const children = new Map();
	for ( const record of table ) {
		const siblings = children.get( record.ppid ) || [];
		siblings.push( record.pid );
		children.set( record.ppid, siblings );
	}
	const captured = [];
	const queue = [ rootPid ];
	while ( queue.length ) {
		const pid = queue.shift();
		const record = byPid.get( pid );
		if ( record ) {
			captured.push( processIdentity( record ) );
			queue.push( ...( children.get( pid ) || [] ) );
		}
	}
	return captured;
}

async function waitForIdentitiesGone( identities, timeoutMs = 10000 ) {
	const { processIdentity, readProcessTable, sameProcessIdentity } = require( SCRIPT );
	const deadline = Date.now() + timeoutMs;
	while ( Date.now() < deadline ) {
		const current = new Map( readProcessTable().map( ( record ) => [ record.pid, processIdentity( record ) ] ) );
		if ( identities.every( ( identity ) => ! sameProcessIdentity( identity, current.get( identity.pid ) ) ) ) {
			return;
		}
		await delay( 25 );
	}
	const current = new Map( readProcessTable().map( ( record ) => [ record.pid, processIdentity( record ) ] ) );
	const survivors = identities.filter( ( identity ) => sameProcessIdentity( identity, current.get( identity.pid ) ) );
	throw new Error( 'Captured process identities survived cleanup: ' + survivors.map( ( identity ) => identity.pid ).join( ', ' ) + '.' );
}

async function waitForNoOwnedProcesses( profilePath, timeoutMs = 10000 ) {
	const deadline = Date.now() + timeoutMs;
	while ( Date.now() < deadline ) {
		if ( 0 === processCommandsContaining( '--user-data-dir=' + profilePath ).length ) {
			return;
		}
		await delay( 50 );
	}
	throw new Error( 'Owned Chrome processes survived for profile ' + profilePath + '.' );
}

function spawnService( temporaryDirectory, environment = {} ) {
	const socketPath = path.join( temporaryDirectory, 'oracle-' + crypto.randomBytes( 4 ).toString( 'hex' ) + '.sock' );
	const child = spawn(
		process.execPath,
		[ SCRIPT, '--serve', '--socket', socketPath ],
		{
			stdio: [ 'ignore', 'pipe', 'pipe' ],
			env: { ...process.env, HTML_API_FUZZ_CHROME_TEST_ALLOW_INTERNAL_COMMANDS: '1', ...environment },
		}
	);
	const service = { child, socketPath, stdout: '', stderr: '' };
	child.stdout.on( 'data', ( chunk ) => {
		service.stdout = ( service.stdout + chunk.toString( 'utf8' ) ).slice( -1024 * 1024 );
	} );
	child.stderr.on( 'data', ( chunk ) => {
		service.stderr = ( service.stderr + chunk.toString( 'utf8' ) ).slice( -1024 * 1024 );
	} );
	return service;
}

async function shutdownService( service ) {
	if ( null !== service.child.exitCode || null !== service.child.signalCode ) {
		return;
	}
	const result = await request( service.socketPath, { id: 'shutdown', command: 'shutdown' } );
	assert( 'ok' === result.status && true === result.shutdown, 'Shutdown request failed.' );
	const outcome = await waitForExit( service.child );
	assert( 0 === outcome.code, 'Graceful service shutdown was not clean.' );
	assert( ! fs.existsSync( service.socketPath ), 'Graceful shutdown left the socket.' );
}

function assertIdentity( ready, chromeExecutable ) {
	assert( 'ready' === ready.status, 'Socket server did not report ready.' );
	assert( ! Object.hasOwn( ready, 'socket' ), 'Ready frame leaked a top-level socket path.' );
	const oracle = ready.oracle;
	const identity = oracle.identity;
	const transport = oracle.transport;
	assert( true === oracle.available, 'Ready oracle was unavailable.' );
	assert( 'chrome-cdp' === identity.kind && 1 === identity.schemaVersion, 'Identity schema mismatch.' );
	assert( '150.0.7871.114' === identity.pinnedChromeVersion, 'Pinned version mismatch.' );
	assert( identity.pinnedChromeVersion === identity.chromeVersion, 'Live Chrome version mismatch.' );
	assert( /^[0-9a-f]{64}$/.test( identity.chromeArchiveSha256 ), 'Archive identity hash missing.' );
	assert( hashFile( chromeExecutable ) === identity.chromeExecutableSha256, 'Chrome executable identity hash mismatch.' );
	assert( hashFile( SCRIPT ) === identity.oracleScriptSha256, 'Oracle script identity hash mismatch.' );
	assert( hashFile( CONTEXT_FILE ) === identity.fragmentContextsSha256, 'Fragment context identity hash mismatch.' );
	assert( JSON.stringify( JSON.parse( fs.readFileSync( CONTEXT_FILE, 'utf8' ) ) ) === JSON.stringify( identity.fragmentContexts ), 'Fragment context identity list mismatch.' );
	assert( hashFile( process.execPath ) === identity.nodeExecutableSha256, 'Node executable identity hash mismatch.' );
	assert( process.version === identity.nodeVersion, 'Node version identity mismatch.' );
	assert( 'string' === typeof identity.cdpProtocolVersion, 'CDP protocol identity missing.' );
	const forbidden = [ 'path', 'pid', 'socket', 'profile', 'endpoint', 'port', 'session', 'target', 'instance' ];
	for ( const key of Object.keys( identity ) ) {
		assert( ! forbidden.some( ( word ) => key.toLowerCase().includes( word ) ), 'Transient key leaked into identity: ' + key );
	}
	assert( true === transport.replayExcluded, 'Transport metadata was not marked replay-excluded.' );
	for ( const key of [ 'ownerPid', 'supervisorPid', 'browserPid', 'runtimeRoot', 'profilePath', 'socketPath', 'debugEndpoint' ] ) {
		assert( undefined !== transport[ key ], 'Transport metadata omitted ' + key + '.' );
	}
}

function assertContextDrift() {
	const contexts = JSON.parse( fs.readFileSync( CONTEXT_FILE, 'utf8' ) );
	const generator = path.join( __dirname, '..', '..', 'lib', 'Generator.php' );
	const code = 'require ' + JSON.stringify( generator ) + '; echo json_encode(HtmlApiFuzz\\Generator::fragment_contexts());';
	const checked = spawnSync( 'php', [ '-r', code ], { encoding: 'utf8', timeout: 5000 } );
	assert( 0 === checked.status, 'Could not read Generator::fragment_contexts().' );
	assert( JSON.stringify( contexts ) === checked.stdout, 'Chrome contexts drifted from Generator::fragment_contexts().' );
	return contexts;
}

function assertStrictCli( temporaryDirectory ) {
	const invalidArgv = [
		[ '--bogus' ],
		[ '--help', '--bogus' ],
		[ '--help', '--chrome-executable', '/tmp/nope' ],
		[ '--version', '--context', 'body' ],
		[ '--version', '--version' ],
		[ '--serve', '--socket', 'relative.sock' ],
		[ '--mode', 'fragment-body', '--input', '/tmp/nope', '--context', 'nope' ],
		[ '--mode', 'fragment-body', '--input', '/tmp/nope', '--max-depth', '01' ],
		[ '--mode', 'fragment-body', '--input', '/tmp/nope', '--max-tree-bytes', String( MAX_TREE_BYTES + 1 ) ],
		[ '--engine', '' ],
		[ '--serve', '--socket', '' ],
		[ '--version', '--chrome-executable', '' ],
		[ '--mode', '', '--input', '/tmp/nope' ],
		[ '--mode', 'fragment-body', '--input', '' ],
		[ '--mode', 'fragment-body', '--input', '/tmp/nope', '--context', '' ],
		[ '--mode', 'fragment-body', '--input', '/tmp/nope', '--max-nodes', '' ],
		[ '--mode', 'fragment-body', '--input', '/tmp/nope', '--max-depth', '' ],
		[ '--mode', 'fragment-body', '--input', '/tmp/nope', '--max-tree-bytes', '' ],
	];
	for ( const argv of invalidArgv ) {
		const checked = spawnSync( process.execPath, [ SCRIPT, ...argv ], { encoding: 'utf8', timeout: 5000 } );
		assert( 0 !== checked.status, 'Invalid CLI was accepted: ' + argv.join( ' ' ) );
		if ( checked.stdout.trim() ) {
			const parsed = JSON.parse( checked.stdout.trim() );
			assert( 'protocol-error' === parsed.failureClass, 'Invalid CLI did not report protocol-error.' );
		}
	}
	const executionLog = path.join( temporaryDirectory, 'untrusted-executed' );
	const fake = path.join( temporaryDirectory, 'fake-chrome' );
	fs.writeFileSync( fake, '#!/bin/sh\nprintf executed > ' + JSON.stringify( executionLog ) + '\nexit 0\n', { mode: 0o700 } );
	const rejected = spawnSync(
		process.execPath,
		[ SCRIPT, '--version', '--chrome-executable', fake ],
		{ encoding: 'utf8', timeout: 10000 }
	);
	assert( 0 !== rejected.status, 'Untrusted custom executable was accepted.' );
	assert( ! fs.existsSync( executionLog ), 'Untrusted custom executable ran before hash rejection.' );
	const insecureParent = path.join( temporaryDirectory, 'insecure-socket-parent' );
	fs.mkdirSync( insecureParent, { mode: 0o500 } );
	const insecure = spawnSync(
		process.execPath,
		[ SCRIPT, '--serve', '--socket', path.join( insecureParent, 'oracle.sock' ) ],
		{ encoding: 'utf8', timeout: 5000 }
	);
	assert( 0 !== insecure.status, 'Non-0700 socket parent was accepted.' );
	assert( 'protocol-error' === JSON.parse( insecure.stdout.trim() ).failureClass, 'Insecure socket parent was not rejected during CLI validation.' );
	fs.chmodSync( insecureParent, 0o700 );
}

async function assertOneShotInputSnapshot( temporaryDirectory ) {
	const input = path.join( temporaryDirectory, 'one-shot-input.html' );
	const replacement = path.join( temporaryDirectory, 'one-shot-input.replacement' );
	const pauseFile = path.join( temporaryDirectory, 'one-shot-input.pause' );
	fs.writeFileSync( input, '<p>original</p>' );
	let stdout = '';
	let stderr = '';
	const child = spawn(
		process.execPath,
		[ SCRIPT, '--mode', 'full-document', '--input', input ],
		{
			stdio: [ 'ignore', 'pipe', 'pipe' ],
			env: { ...process.env, HTML_API_FUZZ_CHROME_TEST_PAUSE_AFTER_INPUT_OPEN: pauseFile },
		}
	);
	child.stdout.on( 'data', ( chunk ) => { stdout += chunk.toString( 'utf8' ); } );
	child.stderr.on( 'data', ( chunk ) => { stderr += chunk.toString( 'utf8' ); } );
	const deadline = Date.now() + 5000;
	while ( ! fs.existsSync( pauseFile ) && Date.now() < deadline ) {
		if ( null !== child.exitCode ) {
			throw new Error( 'One-shot input fixture exited before opening its descriptor. ' + stderr );
		}
		await delay( 10 );
	}
	assert( fs.existsSync( pauseFile ), 'One-shot input fixture did not reach its descriptor pause.' );
	fs.writeFileSync( replacement, Buffer.alloc( MAX_INPUT_BYTES + 1, 0x61 ) );
	fs.renameSync( replacement, input );
	fs.unlinkSync( pauseFile );
	const outcome = await waitForExit( child, 10000 );
	assert( 0 !== outcome.code, 'Replaced one-shot input was accepted.' );
	const result = JSON.parse( stdout.trim() );
	assert( 'input-file-changed' === result.failureClass, 'One-shot input replacement was not rejected as a changed snapshot.' );
	assert( false === result.oracle.available, 'One-shot input replacement launched Chrome before rejection.' );
	assert(
		! Object.hasOwn( result.oracle.transport, 'supervisorPid' ) &&
		! Object.hasOwn( result.oracle.transport, 'runtimeRoot' ),
		'One-shot input replacement created runtime ownership resources.'
	);

	if ( 'win32' !== process.platform ) {
		const fifo = path.join( temporaryDirectory, 'one-shot-input.fifo' );
		const created = spawnSync( 'mkfifo', [ fifo ], { encoding: 'utf8', timeout: 2000 } );
		assert( ! created.error && 0 === created.status, 'Could not create the FIFO input fixture.' );
		const rejected = spawnSync(
			process.execPath,
			[ SCRIPT, '--mode', 'full-document', '--input', fifo ],
			{ encoding: 'utf8', timeout: 2000 }
		);
		assert( ! rejected.error, 'FIFO input without a writer was not rejected within two seconds.' );
		assert( 0 !== rejected.status, 'FIFO input was accepted.' );
		const fifoResult = JSON.parse( rejected.stdout.trim() );
		assert( 'input-byte-limit-exceeded' === fifoResult.failureClass, 'FIFO input had the wrong failure class.' );
		assert( false === fifoResult.oracle.available, 'FIFO input launched Chrome before rejection.' );
	}
}

async function assertRealStdoutWriteDeadline() {
	const child = spawn(
		process.execPath,
		[ SCRIPT, '--serve' ],
		{
			stdio: [ 'pipe', 'pipe', 'pipe' ],
			env: { ...process.env, HTML_API_FUZZ_CHROME_TEST_ALLOW_INTERNAL_COMMANDS: '1' },
		}
	);
	let stderr = '';
	child.stderr.on( 'data', ( chunk ) => { stderr += chunk.toString( 'utf8' ); } );
	let ready = null;
	try {
		const version = waitForReady( child );
		child.stdin.write( JSON.stringify( { command: 'version' } ) + '\n' );
		ready = await version;
		assert( 'ok' === ready.status, 'Persistent stdio fixture did not start Chrome.' );
		child.stdout.removeAllListeners( 'data' );
		child.stdout.pause();
		child.stdin.write( JSON.stringify( { command: 'test-large-response' } ) + '\n' );
		const outcome = await waitForExit( child, 20000 );
		assert( 0 !== outcome.code, 'Blocked real stdout did not fail the service after its write deadline.' );
		await waitForPidGone( ready.oracle.transport.supervisorPid );
		await waitForPidGone( ready.oracle.transport.browserPid );
		await waitForPathGone( ready.oracle.transport.runtimeRoot );
	} catch ( error ) {
		error.message += '\nstderr: ' + stderr;
		throw error;
	} finally {
		if ( null === child.exitCode && null === child.signalCode ) {
			child.kill( 'SIGTERM' );
			await waitForExit( child, 20000 ).catch( () => child.kill( 'SIGKILL' ) );
		}
		child.stdout.destroy();
	}
}

function assertRuntimeManifestStrictness( temporaryDirectory ) {
	const { manifestDigest } = require( SCRIPT );
	const manifest = path.join( temporaryDirectory, 'runtime-manifest' );
	const key = 'platform.executable';
	const digest = 'a'.repeat( 64 );
	fs.writeFileSync( manifest, digest + '  ' + key + '\n' );
	assert( digest === manifestDigest( manifest, key ), 'Valid runtime manifest entry was rejected.' );
	for ( const contents of [
		digest + '  ' + key + '\n' + digest + '  ' + key + ' extra\n',
		digest + '  ' + key + ' extra\n',
		'A'.repeat( 64 ) + '  ' + key + '\n',
	] ) {
		fs.writeFileSync( manifest, contents );
		let rejected = false;
		try {
			manifestDigest( manifest, key );
		} catch ( _error ) {
			rejected = true;
		}
		assert( rejected, 'Malformed or duplicate runtime manifest record was accepted.' );
	}
}

function assertCdpWebSocketProtocol() {
	const { CdpWebSocket } = require( SCRIPT );
	class FakeSocket extends EventEmitter {
		constructor() {
			super();
			this.destroyed = false;
			this.ended = false;
		}
		write() {}
		end() {
			this.ended = true;
		}
		destroy() {
			this.destroyed = true;
		}
	}
	const frame = ( final, opcode, payload, options = {} ) => {
		payload = Buffer.from( payload );
		const first = ( final ? 0x80 : 0 ) | ( options.rsv || 0 ) | opcode;
		let header;
		if ( payload.length < 126 ) {
			header = Buffer.from( [ first, ( options.masked ? 0x80 : 0 ) | payload.length ] );
		} else {
			header = Buffer.alloc( 4 );
			header[ 0 ] = first;
			header[ 1 ] = ( options.masked ? 0x80 : 0 ) | 126;
			header.writeUInt16BE( payload.length, 2 );
		}
		if ( ! options.masked ) {
			return Buffer.concat( [ header, payload ] );
		}
		const mask = Buffer.from( [ 1, 2, 3, 4 ] );
		const masked = Buffer.from( payload );
		for ( let index = 0; index < masked.length; index++ ) {
			masked[ index ] ^= mask[ index % 4 ];
		}
		return Buffer.concat( [ header, mask, masked ] );
	};
	const assertInvalid = ( frames, label, maxBytes = 256 ) => {
		const socket = new FakeSocket();
		const websocket = new CdpWebSocket( socket, Buffer.alloc( 0 ), maxBytes );
		let failure = null;
		websocket.onClose( ( error ) => {
			failure = error;
		} );
		for ( const encoded of frames ) {
			if ( ! socket.destroyed ) {
				websocket.consume( encoded );
			}
		}
		assert( socket.destroyed && failure, label + ' did not terminate the CDP channel.' );
		assert( 'oracle-infrastructure-failure' === failure.failureClass, label + ' was not infrastructure failure.' );
		assert( failure.invalidateSession && ! failure.recoverableSessionDeath, label + ' was incorrectly recoverable.' );
		return websocket;
	};
	const socket = new FakeSocket();
	const websocket = new CdpWebSocket( socket, Buffer.alloc( 0 ), 16 );
	websocket.consume( frame( false, 0x1, Buffer.alloc( 10, 0x61 ) ) );
	assert( ! socket.destroyed, 'First bounded CDP fragment was rejected.' );
	websocket.consume( frame( false, 0x0, Buffer.alloc( 7, 0x62 ) ) );
	assert( socket.destroyed, 'Fragmented CDP input crossed its cumulative cap without immediate rejection.' );
	assert( websocket.fragments.length <= 1, 'Crossing CDP fragment was retained before rejection.' );

	for ( const [ frames, label ] of [
		[ [ frame( true, 0x1, '{}', { masked: true } ) ], 'masked server frame' ],
		[ [ frame( true, 0x1, '{}', { rsv: 0x40 } ) ], 'RSV server frame' ],
		[ [ frame( true, 0x3, '{}' ) ], 'unknown opcode' ],
		[ [ frame( true, 0x0, '{}' ) ], 'orphan continuation' ],
		[ [ frame( false, 0x1, '{' ), frame( true, 0x1, '}' ) ], 'interrupted fragmented message' ],
		[ [ frame( false, 0x9, 'x' ) ], 'fragmented control frame' ],
		[ [ frame( true, 0x9, Buffer.alloc( 126 ) ) ], 'oversized control frame' ],
		[ [ frame( true, 0x1, Buffer.from( [ 0xc3, 0x28 ] ) ) ], 'invalid text UTF-8' ],
		[ [ frame( true, 0x2, '{}' ) ], 'binary CDP message' ],
		[ [ frame( true, 0x8, Buffer.from( [ 0x03, 0xe7 ] ) ) ], 'out-of-range close status' ],
		[ [ frame( true, 0x8, Buffer.from( [ 0x03, 0xed ] ) ) ], 'reserved close status' ],
	] ) {
		assertInvalid( frames, label );
	}

	const validSocket = new FakeSocket();
	const valid = new CdpWebSocket( validSocket, Buffer.alloc( 0 ), 256 );
	let message = null;
	valid.onMessage( ( value ) => {
		message = value;
	} );
	valid.consume( Buffer.concat( [
		frame( false, 0x1, 'ab' ),
		frame( true, 0x9, 'ping' ),
		frame( true, 0x0, 'cd' ),
	] ) );
	assert( ! validSocket.destroyed && 'abcd' === message, 'Valid fragmented text with interleaved ping was rejected.' );
	const closePayload = Buffer.alloc( 2 );
	closePayload.writeUInt16BE( 1000 );
	valid.consume( frame( true, 0x8, closePayload ) );
	assert( validSocket.ended && valid.closed && ! validSocket.destroyed, 'Valid WebSocket close status was rejected.' );

	const closingSocket = new FakeSocket();
	const closing = new CdpWebSocket( closingSocket, Buffer.alloc( 0 ), 256 );
	let postCloseMessages = 0;
	closing.onMessage( () => {
		postCloseMessages++;
	} );
	closing.consume( Buffer.concat( [ frame( true, 0x8, closePayload ), frame( true, 0x1, '{}' ) ] ) );
	closing.consume( frame( true, 0x1, '{}' ) );
	assert( closing.closed && closingSocket.ended, 'Server close did not immediately terminate the CDP channel.' );
	assert( 0 === postCloseMessages, 'CDP text was delivered after a server close frame.' );
	let closedSendError = null;
	try {
		closing.sendJson( { id: 1 } );
	} catch ( error ) {
		closedSendError = error;
	}
	assert(
		closedSendError === closing.closeError && closedSendError.transportFailure && closedSendError.recoverableSessionDeath,
		'Send-on-closed CDP channel lost its authenticated session-death classification.'
	);
	const errorSocket = new FakeSocket();
	const errored = new CdpWebSocket( errorSocket, Buffer.alloc( 0 ), 256 );
	let socketFailure = null;
	errored.onClose( ( error ) => {
		socketFailure = error;
	} );
	errorSocket.emit( 'error', new Error( 'synthetic I/O death' ) );
	errorSocket.emit( 'close' );
	let erroredSend = null;
	try {
		errored.sendJson( { id: 2 } );
	} catch ( error ) {
		erroredSend = error;
	}
	assert(
		socketFailure === errored.closeError &&
		erroredSend === socketFailure &&
		socketFailure.transportFailure &&
		socketFailure.recoverableSessionDeath &&
		! socketFailure.invalidateSession,
		'CDP socket I/O death was not retained as recoverable closed-session evidence.'
	);
}

function assertSupervisorBackpressure() {
	const { createSupervisorEmitter } = require( SCRIPT );
	const output = new EventEmitter();
	output.destroyed = false;
	output.frames = [];
	output.write = ( frame ) => {
		output.frames.push( frame );
		return 1 !== output.frames.length;
	};
	const emitter = createSupervisorEmitter( output );
	assert( emitter.emit( { event: 'ready' } ), 'Initial critical supervisor event was not emitted.' );
	assert( emitter.isBackpressured(), 'Supervisor emitter did not record stdout backpressure.' );
	assert( ! emitter.emit( { event: 'processes' }, true ), 'Lossy heartbeat was queued during backpressure.' );
	assert( 1 === output.frames.length, 'Backpressured heartbeat grew the stdout queue.' );
	assert( emitter.emit( { event: 'browser-exit' } ), 'Bounded critical event was dropped during backpressure.' );
	assert( 2 === output.frames.length, 'Critical event count mismatch during backpressure.' );
	output.emit( 'drain' );
	assert( emitter.emit( { event: 'processes' }, true ), 'Heartbeat did not resume after stdout drain.' );
	assert( 3 === output.frames.length, 'Post-drain heartbeat was not emitted.' );
	const oversized = { event: 'processes', payload: 'x'.repeat( 1024 * 1024 ) };
	assert( ! emitter.emit( oversized, true ), 'Oversized lossy supervisor event was accepted.' );
	let criticalRejected = false;
	try {
		emitter.emit( oversized );
	} catch ( error ) {
		criticalRejected = error.message.includes( 'exceeded 1 MiB' );
	}
	assert( criticalRejected, 'Oversized critical supervisor event was not rejected.' );
}

async function assertSupervisorProtocolValidation() {
	const { ChromeOracle } = require( SCRIPT );
	const startupReject = ( frame, label ) => {
		const oracle = new ChromeOracle( {} );
		const supervisor = {};
		oracle.supervisor = supervisor;
		let readyFailure = null;
		let startupFailure = null;
		const state = {
			supervisor,
			processSnapshots: [],
			active: false,
			intentionalExit: false,
			readyEventSeen: false,
			supervisorProtocolFailure: null,
			rejectStartup: ( error ) => { startupFailure = error; },
		};
		oracle.consumeSupervisorOutput( frame, state, { resolve() {}, reject: ( error ) => { readyFailure = error; } } );
		assert(
			readyFailure && readyFailure === startupFailure && readyFailure === state.supervisorProtocolFailure,
			label + ' did not latch one startup infrastructure failure.'
		);
		assert( 'oracle-infrastructure-failure' === readyFailure.failureClass, label + ' was not infrastructure failure.' );
		assert( 0 === oracle.supervisorEventsBuffer.length, label + ' retained supervisor bytes after rejection.' );
	};
	startupReject( Buffer.from( '{"event":"unknown"}\n' ), 'Unknown supervisor event' );
	startupReject( Buffer.from( '{"event":"cleaned","reason":"unexpected"}\n' ), 'Pre-healthy supervisor cleaned event' );
	startupReject(
		Buffer.from( JSON.stringify( {
			event: 'ready', browserPid: 42, executableSha256: 'a'.repeat( 64 ),
			endpoint: 'ws://127.0.0.1/x',
			processes: [ { pid: 42, ppid: 1, birth: 'synthetic', command: 'chrome' } ],
			surplus: true,
		} ) + '\n' ),
		'Surplus supervisor ready field'
	);
	{
		const oracle = new ChromeOracle( {} );
		const supervisor = {};
		oracle.supervisor = supervisor;
		let startupFailure = null;
		const state = {
			supervisor,
			processSnapshots: [],
			active: false,
			intentionalExit: false,
			readyEventSeen: true,
			supervisorProtocolFailure: null,
			rejectStartup: ( error ) => { startupFailure = error; },
		};
		oracle.consumeSupervisorOutput(
			Buffer.from( '{"event":"startup-error","error":"synthetic late failure"}\n' ),
			state,
			{ resolve() {}, reject() {} }
		);
		assert(
			startupFailure && startupFailure === state.supervisorExit,
			'Startup-error after ready did not reject the still-inactive startup.'
		);
	}

	const activeReject = async ( frame, label ) => {
		const oracle = new ChromeOracle( {} );
		const supervisor = {};
		oracle.supervisor = supervisor;
		let fatalFailure = null;
		oracle.handleSupervisorInfrastructureFailure = async ( error ) => { fatalFailure = error; };
		const state = {
			supervisor,
			processSnapshots: [],
			active: true,
			intentionalExit: false,
			readyEventSeen: true,
			supervisorProtocolFailure: null,
			rejectStartup() {},
		};
		oracle.consumeSupervisorOutput( frame, state, { resolve() {}, reject() {} } );
		await delay( 0 );
		assert(
			fatalFailure && fatalFailure === state.supervisorProtocolFailure,
			label + ' did not trigger fatal active-service teardown.'
		);
		assert( 0 === oracle.supervisorEventsBuffer.length, label + ' retained an unbounded supervisor frame.' );
	};
	await activeReject( Buffer.from( '{bad json}\n' ), 'Malformed active supervisor JSON' );
	await activeReject( Buffer.alloc( 1024 * 1024 + 1, 0x78 ), 'Oversized active supervisor frame' );
}

function assertSnapshotRetentionBound() {
	const { retainProcessSnapshot } = require( SCRIPT );
	const launchState = { processSnapshots: [] };
	let activeSnapshots = [];
	for ( let index = 0; index < 20; index++ ) {
		activeSnapshots = retainProcessSnapshot( launchState, [ { pid: index } ] );
	}
	assert( 8 === launchState.processSnapshots.length, 'Launch-state supervisor snapshots grew beyond eight.' );
	assert( 8 === activeSnapshots.length, 'Active supervisor snapshots grew beyond eight.' );
	assert( 12 === launchState.processSnapshots[ 0 ][ 0 ].pid, 'Supervisor snapshot retention did not keep the newest eight.' );
}

async function assertBoundedPeerAndCleanupInfrastructure( temporaryDirectory ) {
	const {
		ChromeOracle,
		FramePeer,
		processIdentity,
		readProcessTable,
		reapAndRemoveRuntimeResources,
		reapAuthenticatedTree,
		serializedDispatcher,
		writeJsonFrame,
	} = require( SCRIPT );
	class FakeStream extends EventEmitter {
		constructor( writable = true ) {
			super();
			this.destroyed = false;
			this.writable = writable;
			this.frames = [];
		}
		pause() {}
		resume() {}
		write( frame ) {
			this.frames.push( frame );
			return true;
		}
		end() {
			this.destroyed = true;
			this.emit( 'close' );
		}
		destroy() {
			this.destroyed = true;
			this.emit( 'close' );
		}
	}

	let releaseDispatch;
	const dispatchGate = new Promise( ( resolve ) => {
		releaseDispatch = resolve;
	} );
	const dispatcher = serializedDispatcher();
	const blocker = dispatcher( () => dispatchGate );
	let renders = 0;
	const input = new FakeStream();
	const output = new FakeStream();
	const peer = new FramePeer(
		input,
		output,
		{ metadata: () => ( {} ), render: async () => { renders++; return { status: 'ok' }; } },
		dispatcher,
		{ singleFrame: true, cancelOnDisconnect: true, readTimeoutMs: 1000, writeTimeoutMs: 1000 }
	);
	input.emit( 'data', Buffer.from( '{"command":"render","htmlBase64":"","mode":"fragment-body","context":"body"}\n' ) );
	input.emit( 'close' );
	releaseDispatch();
	await blocker;
	await peer.processing;
	assert( 0 === renders, 'Disconnected queued socket request reached the oracle dispatcher.' );

	const idleInput = new FakeStream();
	const idlePeer = new FramePeer(
		idleInput,
		new FakeStream(),
		{ metadata: () => ( {} ) },
		serializedDispatcher(),
		{ closeOnOversize: true, singleFrame: true, readTimeoutMs: 20, writeTimeoutMs: 20 }
	);
	await Promise.race( [
		idlePeer.closedPromise,
		delay( 200 ).then( () => { throw new Error( 'Idle socket frame deadline did not close its peer.' ); } ),
	] );
	const defaultDeadlinePeer = new FramePeer(
		new FakeStream(),
		new FakeStream(),
		{ metadata: () => ( {} ) },
		serializedDispatcher()
	);
	assert( 10000 === defaultDeadlinePeer.writeTimeoutMs, 'Persistent stdio peer did not inherit a finite write deadline.' );
	defaultDeadlinePeer.finish();

	const closedOutput = new FakeStream();
	closedOutput.write = () => false;
	const closedWrite = writeJsonFrame( closedOutput, { status: 'ok' } );
	setTimeout( () => closedOutput.emit( 'close' ), 10 );
	let closedWriteError = null;
	try {
		await closedWrite;
	} catch ( error ) {
		closedWriteError = error;
	}
	assert( 'HTML_API_FUZZ_OUTPUT_CLOSED' === closedWriteError?.code, 'Backpressured close did not settle the response writer.' );
	const timedOutput = new FakeStream();
	timedOutput.write = () => false;
	let timedWriteError = null;
	try {
		await writeJsonFrame( timedOutput, { status: 'ok' }, 20 );
	} catch ( error ) {
		timedWriteError = error;
	}
	assert( 'HTML_API_FUZZ_OUTPUT_TIMEOUT' === timedWriteError?.code, 'Backpressured response did not honor its drain deadline.' );
	assert( timedOutput.destroyed, 'Timed-out response stream retained its queued write resource.' );

	const profilePath = '/tmp/html-api-fuzz-synthetic-profile';
	const token = 'synthetic-owner-token';
	const table = [];
	for ( let index = 0; index < 20; index++ ) {
		table.push( {
			pid: 900000 + index,
			ppid: 0 === index ? 1 : 900000,
			start: 'Mon Jan  1 00:00:00 2024',
			command: 0 === index
				? 'chrome --user-data-dir=' + profilePath + ' --html-api-fuzz-owner-token=' + token
				: 'chrome helper ' + index,
		} );
	}
	const snapshots = [ table.map( processIdentity ) ];
	let tableLive = true;
	let tableCalls = 0;
	let termSignals = 0;
	await reapAuthenticatedTree( snapshots, profilePath, token, {
		tableReader: () => {
			tableCalls++;
			return tableLive ? table : [];
		},
		signalProcess: ( _pid, signal ) => {
			if ( 'SIGTERM' === signal && ++termSignals === table.length ) {
				tableLive = false;
			}
		},
		totalTimeoutMs: 200,
		termTimeoutMs: 100,
		pollMilliseconds: 5,
	} );
	assert( table.length === termSignals, 'Synthetic multi-descendant cleanup missed a process.' );
	assert( tableCalls <= 3, 'Cleanup inspected the process table per PID instead of per pass.' );
	let inspectionFailure = null;
	try {
		await reapAuthenticatedTree( [], profilePath, token, {
			tableReader: () => { throw new Error( 'synthetic ps failure' ); },
			totalTimeoutMs: 50,
		} );
	} catch ( error ) {
		inspectionFailure = error;
	}
	assert( inspectionFailure?.message.includes( 'synthetic ps failure' ), 'Process-inspection failure did not fail cleanup closed.' );
	const psStart = Date.now();
	try {
		readProcessTable( 1 );
	} catch ( _error ) {}
	assert( Date.now() - psStart < 500, 'Synchronous ps inspection ignored its supplied subprocess timeout.' );
	const delayedStart = Date.now();
	let deadlineFailure = null;
	try {
		await reapAuthenticatedTree( [], profilePath, token, {
			tableReader: ( remaining ) => {
				const waitUntil = Date.now() + remaining + 10;
				while ( Date.now() < waitUntil ) {}
				return [];
			},
			totalTimeoutMs: 25,
		} );
	} catch ( error ) {
		deadlineFailure = error;
	}
	assert( deadlineFailure?.message.includes( 'deadline' ), 'Delayed process inspection escaped the absolute cleanup deadline.' );
	assert( Date.now() - delayedStart < 80, 'Synchronous process inspection exceeded the synthetic wall bound.' );

	const failedReapRuntime = path.join( temporaryDirectory, 'retained-failed-reap-runtime' );
	fs.mkdirSync( failedReapRuntime );
	let failedReap = null;
	try {
		await reapAndRemoveRuntimeResources( [], profilePath, token, failedReapRuntime, '', {
			tableReader: () => { throw new Error( 'synthetic retained tree' ); },
		} );
	} catch ( error ) {
		failedReap = error;
	}
	assert( failedReap?.message.includes( 'synthetic retained tree' ), 'Failed authenticated reap was not reported.' );
	assert( fs.existsSync( failedReapRuntime ), 'Runtime was removed after authenticated tree reaping failed.' );
	fs.rmSync( failedReapRuntime, { recursive: true, force: true } );

	const retainedRuntime = path.join( temporaryDirectory, 'retained-live-supervisor-runtime' );
	fs.mkdirSync( retainedRuntime );
	const oracle = new ChromeOracle( {} );
	const signals = [];
	oracle.waitForSupervisorExit = async () => false;
	let disposeFailure = null;
	try {
		await oracle.disposeState( {
			supervisor: {
				exitCode: null,
				signalCode: null,
				stdin: { destroyed: true },
				kill: ( signal ) => signals.push( signal ),
			},
			runtimeRoot: retainedRuntime,
			profilePath: null,
			ownershipToken: null,
			processSnapshots: [],
			websocket: null,
			cdp: null,
			targetId: null,
		}, false );
	} catch ( error ) {
		disposeFailure = error;
	}
	assert( 'oracle-infrastructure-failure' === disposeFailure?.failureClass, 'Surviving supervisor cleanup was not infrastructure failure.' );
	assert( 'SIGTERM,SIGKILL' === signals.join( ',' ), 'Surviving supervisor did not receive bounded TERM/KILL escalation.' );
	assert( fs.existsSync( retainedRuntime ), 'Runtime was removed underneath a supervisor reported alive.' );
	fs.rmSync( retainedRuntime, { recursive: true, force: true } );
}

async function assertCdpEnvelopeValidation() {
	const { CdpClient } = require( SCRIPT );
	const assertInvalid = async ( payload, label, sessionId = undefined ) => {
		let terminated = null;
		const websocket = {
			onMessage() {},
			onClose() {},
			sendJson() {},
			terminate( error ) {
				terminated = error;
			},
		};
		const cdp = new CdpClient( websocket );
		const pending = cdp.send( 'Browser.getVersion', {}, sessionId, 1000 );
		cdp.receive( payload );
		let rejected = null;
		try {
			await pending;
		} catch ( error ) {
			rejected = error;
		}
		assert( rejected && rejected === terminated, label + ' did not terminate and reject the CDP channel consistently.' );
		assert( 'oracle-infrastructure-failure' === rejected.failureClass, label + ' was not infrastructure failure.' );
		assert( rejected.transportFailure && rejected.invalidateSession, label + ' did not invalidate the CDP session.' );
		assert( ! rejected.recoverableSessionDeath, label + ' was incorrectly marked recoverable.' );
	};
	for ( const [ payload, label ] of [
		[ '{', 'invalid JSON' ],
		[ 'null', 'null envelope' ],
		[ '1', 'primitive envelope' ],
		[ '[]', 'array envelope' ],
		[ '{"id":0,"result":{}}', 'invalid response id' ],
		[ '{"id":1,"result":{},"error":{"code":-1,"message":"x"}}', 'ambiguous response' ],
		[ '{"id":1,"result":false}', 'scalar result' ],
		[ '{"id":1,"error":{"code":"-1","message":"x"}}', 'malformed error' ],
		[ '{"method":"Page.event","params":false}', 'scalar event params' ],
		[ '{"id":1,"result":{},"extra":true}', 'unknown envelope field' ],
		[ '{"id":2,"result":{}}', 'unknown response id' ],
		[ '{"id":1,"result":{},"sessionId":"unexpected"}', 'unexpected browser response session' ],
	] ) {
		await assertInvalid( payload, label );
	}
	await assertInvalid( '{"id":1,"result":{}}', 'missing session response route', 'expected-session' );
	await assertInvalid( '{"id":1,"result":{},"sessionId":"wrong-session"}', 'wrong session response route', 'expected-session' );

	let terminated = null;
	const websocket = {
		onMessage() {},
		onClose() {},
		sendJson() {},
		terminate( error ) {
			terminated = error;
		},
	};
	const cdp = new CdpClient( websocket );
	const valid = cdp.send( 'Browser.getVersion', {}, undefined, 1000 );
	cdp.receive( '{"id":1,"result":{"product":"test"}}' );
	assert( 'test' === ( await valid ).product, 'Valid CDP response did not resolve exactly.' );
	cdp.receive( '{"id":1,"result":{"product":"duplicate"}}' );
	assert( terminated && 'oracle-infrastructure-failure' === terminated.failureClass, 'Duplicate CDP response id did not terminate the channel.' );

	const sessionWebsocket = {
		onMessage() {},
		onClose() {},
		sendJson() {},
	};
	const sessionCdp = new CdpClient( sessionWebsocket );
	const validSession = sessionCdp.send( 'Runtime.evaluate', {}, 'expected-session', 1000 );
	sessionCdp.receive( '{"id":1,"result":{"value":1},"sessionId":"expected-session"}' );
	assert( 1 === ( await validSession ).value, 'Valid session-routed CDP response did not resolve exactly.' );
}

async function assertTimeoutDoesNotReplay() {
	const { CdpClient, ChromeOracle } = require( SCRIPT );
	let sends = 0;
	const websocket = {
		onMessage() {},
		onClose() {},
		sendJson() {
			sends++;
		},
	};
	const cdp = new CdpClient( websocket );
	let timeout;
	try {
		await cdp.send( 'Runtime.evaluate', {}, 'session', 5 );
	} catch ( error ) {
		timeout = error;
	}
	assert( timeout, 'Synthetic Runtime.evaluate did not time out.' );
	assert( 'oracle-evaluation-timeout' === timeout.failureClass, 'Evaluation timeout classification mismatch.' );
	assert( ! timeout.transportFailure && ! timeout.recoverableSessionDeath, 'Live evaluation timeout was marked recoverable.' );
	assert( 1 === sends, 'CDP timeout sent more than one command.' );

	const oracle = new ChromeOracle( {} );
	oracle.validateRequest = () => ( {} );
	oracle.healthy = false;
	oracle.supervisor = null;
	let startCount = 0;
	let startCalls = 0;
	oracle.start = async () => {
		startCalls++;
		if ( ! oracle.healthy ) {
			startCount++;
			oracle.healthy = true;
			oracle.supervisor = {};
		}
	};
	let resetCount = 0;
	oracle.resetState = async () => {
		resetCount++;
		oracle.healthy = false;
		oracle.supervisor = null;
	};
	let renderCount = 0;
	oracle.renderNow = async () => {
		renderCount++;
		throw timeout;
	};
	let rejected = false;
	try {
		await oracle.renderWithRecovery( {} );
	} catch ( error ) {
		rejected = error === timeout;
	}
	assert( rejected, 'Evaluation timeout was not returned unchanged.' );
	assert( 1 === renderCount, 'Evaluation timeout replayed the render.' );
	assert( 1 === resetCount, 'Evaluation timeout did not tear down its session exactly once.' );
	assert( 1 === startCount, 'Cold evaluation timeout started more than one session.' );
	assert( 1 === startCalls, 'Evaluation timeout crossed a redundant start boundary.' );

	const infrastructure = new Error( 'synthetic live infrastructure failure' );
	infrastructure.transportFailure = true;
	renderCount = 0;
	resetCount = 0;
	startCount = 0;
	startCalls = 0;
	oracle.healthy = true;
	oracle.supervisor = {};
	oracle.lastTransportError = null;
	oracle.renderNow = async () => {
		renderCount++;
		throw infrastructure;
	};
	rejected = false;
	try {
		await oracle.renderWithRecovery( {} );
	} catch ( error ) {
		rejected = error === infrastructure;
	}
	assert( rejected, 'Unconfirmed infrastructure failure was not returned unchanged.' );
	assert( 1 === renderCount, 'Unconfirmed infrastructure failure replayed the render.' );
	assert( 0 === resetCount, 'Unconfirmed infrastructure failure was treated as an authenticated dead session.' );
	assert( 1 === startCalls, 'Live infrastructure failure crossed a redundant start boundary.' );

	const sessionDeath = new Error( 'synthetic authenticated CDP session death' );
	sessionDeath.transportFailure = true;
	sessionDeath.recoverableSessionDeath = true;
	renderCount = 0;
	resetCount = 0;
	startCount = 0;
	startCalls = 0;
	oracle.healthy = false;
	oracle.supervisor = null;
	oracle.resetState = async () => {
		resetCount++;
		oracle.healthy = false;
		oracle.supervisor = null;
	};
	oracle.renderNow = async () => {
		renderCount++;
		if ( 1 === renderCount ) {
			throw sessionDeath;
		}
		return { status: 'ok' };
	};
	const recovered = await oracle.renderWithRecovery( {} );
	assert( 'ok' === recovered.status, 'Observed session death did not recover.' );
	assert( 2 === renderCount && 1 === resetCount, 'Observed session death did not retry exactly once.' );
	assert( 2 === startCount, 'Cold observed session death did not create exactly one replacement session.' );
	assert( 2 === startCalls, 'Observed session death did not use one start boundary per attempt.' );

	const protocolFailure = new Error( 'synthetic trusted renderer protocol failure' );
	protocolFailure.transportFailure = true;
	protocolFailure.invalidateSession = true;
	protocolFailure.failureClass = 'oracle-infrastructure-failure';
	const secondSessionDeath = new Error( 'synthetic second authenticated session death' );
	secondSessionDeath.transportFailure = true;
	secondSessionDeath.recoverableSessionDeath = true;
	const correlatedTransportFailure = new Error( 'synthetic retry WebSocket reset' );
	correlatedTransportFailure.transportFailure = true;
	for ( const [ finalFailure, label, correlateDeath ] of [
		[ timeout, 'timeout', false ],
		[ protocolFailure, 'protocol failure', false ],
		[ secondSessionDeath, 'session death', false ],
		[ correlatedTransportFailure, 'correlated transport death', true ],
	] ) {
		renderCount = 0;
		resetCount = 0;
		startCount = 0;
		startCalls = 0;
		oracle.healthy = false;
		oracle.supervisor = null;
		oracle.lastTransportError = null;
		oracle.resetState = async () => {
			resetCount++;
			oracle.healthy = false;
			oracle.supervisor = null;
			oracle.lastTransportError = null;
		};
		oracle.renderNow = async () => {
			renderCount++;
			if ( 1 === renderCount ) {
				throw sessionDeath;
			}
			if ( correlateDeath ) {
				setTimeout( () => {
					oracle.lastTransportError = secondSessionDeath;
				}, 10 );
			}
			throw finalFailure;
		};
		let finalRejected = null;
		try {
			await oracle.renderWithRecovery( {} );
		} catch ( error ) {
			finalRejected = error;
		}
		assert( finalRejected === finalFailure, 'Second-attempt ' + label + ' was not propagated unchanged.' );
		assert( 2 === renderCount, 'Second-attempt ' + label + ' caused a third render.' );
		assert( 2 === startCount && 2 === startCalls, 'Second-attempt ' + label + ' crossed the wrong start boundaries.' );
		assert( 2 === resetCount, 'Second-attempt ' + label + ' did not quarantine both failed sessions.' );
		assert( ! oracle.healthy && null === oracle.supervisor, 'Second-attempt ' + label + ' retained a failed session.' );
	}

	const cleanupFailure = new Error( 'synthetic authenticated cleanup failure' );
	renderCount = 0;
	startCount = 0;
	startCalls = 0;
	oracle.healthy = false;
	oracle.supervisor = null;
	oracle.lastTransportError = null;
	oracle.resetState = async () => {
		throw cleanupFailure;
	};
	oracle.renderNow = async () => {
		renderCount++;
		throw sessionDeath;
	};
	let cleanupRejected = null;
	try {
		await oracle.renderWithRecovery( {} );
	} catch ( error ) {
		cleanupRejected = error;
	}
	assert( cleanupRejected instanceof AggregateError, 'Session-death cleanup failure did not preserve both errors.' );
	assert( 'oracle-infrastructure-failure' === cleanupRejected.failureClass, 'Cleanup failure was not infrastructure-classified.' );
	assert( cleanupRejected.errors.includes( sessionDeath ) && cleanupRejected.errors.includes( cleanupFailure ), 'Cleanup aggregate lost its causes.' );
	assert( 1 === renderCount && 1 === startCalls, 'Cleanup failure started a retry after incomplete teardown.' );

	const supervisorFailureOracle = new ChromeOracle( {} );
	const supervisorCleanupFailure = new Error( 'synthetic supervisor-exit cleanup failure' );
	let fatalError = null;
	supervisorFailureOracle.resetState = async () => {
		throw supervisorCleanupFailure;
	};
	supervisorFailureOracle.setFatalHandler( async ( error ) => {
		fatalError = error;
	} );
	await supervisorFailureOracle.handleUnexpectedSupervisorExit( 9, null );
	assert( fatalError instanceof AggregateError, 'Supervisor cleanup failure did not preserve both errors.' );
	assert( fatalError.transportFailure && 'oracle-infrastructure-failure' === fatalError.failureClass, 'Supervisor cleanup failure was not infrastructure-classified.' );
	assert( fatalError.errors.includes( supervisorCleanupFailure ), 'Supervisor cleanup aggregate lost its cleanup cause.' );
}

async function assertRendererOutputValidation() {
	const { ChromeOracle } = require( SCRIPT );
	const validRendered = {
		status: 'ok',
		treeBase64: 'Cg==',
		treeBytes: 1,
		nodeCount: 0,
	};
	const assertInvalid = async ( responses, securityAudit, label ) => {
		const oracle = new ChromeOracle( {} );
		const validated = {
			html: '',
			mode: 'fragment-body',
			context: 'body',
			limits: { maxNodes: 10, maxDepth: 10, maxTreeBytes: 1024 },
			invalidUtf8: false,
			securityAudit,
		};
		let sendCount = 0;
		let resetCount = 0;
		let startCount = 0;
		oracle.validateRequest = () => validated;
		oracle.start = async () => {
			if ( ! oracle.healthy ) {
				startCount++;
				oracle.healthy = true;
				oracle.supervisor = {};
			}
		};
		oracle.cdp = {
			send: async () => responses[ sendCount++ ],
		};
		oracle.sessionId = 'synthetic-session';
		oracle.healthy = false;
		oracle.supervisor = null;
		oracle.resetState = async () => {
			resetCount++;
			oracle.healthy = false;
			oracle.supervisor = null;
		};
		let rejected = null;
		try {
			await oracle.renderWithRecovery( {} );
		} catch ( error ) {
			rejected = error;
		}
		assert( rejected && 'oracle-infrastructure-failure' === rejected.failureClass, label + ' was not infrastructure failure.' );
		assert( rejected.invalidateSession && ! rejected.recoverableSessionDeath, label + ' was incorrectly recoverable.' );
		assert( 1 === resetCount, label + ' did not tear down its session exactly once.' );
		assert( 1 === startCount, label + ' did not quarantine a single cold-start session.' );
		assert( responses.length === sendCount, label + ' replayed input or issued an unexpected CDP command.' );
	};
	await assertInvalid( [ { result: { value: null } } ], false, 'Null renderer value' );
	await assertInvalid( [ { result: { value: { ...validRendered, nodeCount: '0' } } } ], false, 'Malformed renderer counter' );
	await assertInvalid( [ { result: { value: { ...validRendered, surplus: true } } } ], false, 'Surplus renderer field' );
	const missingRendererField = { ...validRendered };
	delete missingRendererField.treeBytes;
	await assertInvalid( [ { result: { value: missingRendererField } } ], false, 'Missing renderer field' );
	await assertInvalid( [ { result: { value: {
		status: 'limit',
		failureClass: 'node-limit-exceeded',
		error: 'bounded',
		nodeCount: 11,
		treeBytes: 0,
		surplus: true,
	} } } ], false, 'Surplus non-success renderer field' );
	await assertInvalid( [ { result: { value: { ...validRendered, treeBase64: 'Cg==', treeBytes: 2 } } } ], false, 'Inconsistent renderer tree' );
	await assertInvalid( [ { result: { value: {
		status: 'error', failureClass: 'invented-renderer-class', error: 'synthetic', nodeCount: 0, treeBytes: 0,
	} } } ], false, 'Unknown renderer failure class' );
	await assertInvalid( [ { result: { value: {
		status: 'limit', failureClass: 'invented-limit-exceeded', error: 'synthetic', nodeCount: 0, treeBytes: 0,
	} } } ], false, 'Unknown renderer limit class' );
	await assertInvalid( [ { result: { value: {
		...validRendered, treeBase64: '/w==', treeBytes: 1,
	} } } ], false, 'Invalid UTF-8 renderer tree' );
	await assertInvalid( [ { exceptionDetails: { text: 'escaped' }, result: {} } ], false, 'Escaped renderer exception' );
	await assertInvalid( [
		{ result: { value: validRendered } },
		{ result: { value: { authorRan: 'false', activeMarkup: '', resources: [] } } },
	], true, 'Malformed security audit' );
	await assertInvalid( [
		{ result: { value: validRendered } },
		{ result: { value: { authorRan: false, activeMarkup: '', resources: [], surplus: true } } },
	], true, 'Surplus security-audit field' );
	await assertInvalid( [
		{ result: { value: validRendered } },
		{ result: { value: { authorRan: false, activeMarkup: '' } } },
	], true, 'Missing security-audit field' );
}

async function assertCanonicalAndSecurity( service, contexts ) {
	const fullHtml = '<!doctype html><html><body><div b=\"2\" a=\"1\">x<!--c-->' +
		'<template><span>t</span></template></div></body></html>';
	const documentResult = await request( service.socketPath, {
		id: 1,
		command: 'render',
		mode: 'full-document',
		htmlBase64: Buffer.from( fullHtml ).toString( 'base64' ),
		maxNodes: 1000,
		maxDepth: 64,
		maxTreeBytes: 1024 * 1024,
	} );
	const expected = '<!DOCTYPE html>\n<html>\n  <head>\n  <body>\n    <div>\n' +
		'      a=\"1\"\n      b=\"2\"\n      \"x\"\n      <!-- c -->\n      <template>\n' +
		'        content\n          <span>\n            \"t\"\n\n';
	assert( expected === decodeTree( documentResult ), 'Canonical full-document tree mismatch.' );
	const nonAsciiName = await request( service.socketPath, {
		command: 'render',
		mode: 'fragment-body',
		context: 'body',
		htmlBase64: Buffer.from( '<Aİ></Aİ>' ).toString( 'base64' ),
		maxNodes: 10,
		maxDepth: 10,
		maxTreeBytes: 1024,
	} );
	const nonAsciiTree = decodeTree( nonAsciiName );
	assert(
		'<aİ>\n\n' === nonAsciiTree,
		'Non-ASCII HTML tag name was Unicode-lowercased: ' + JSON.stringify( nonAsciiTree )
	);
	const processingInstruction = await request( service.socketPath, {
		command: 'test-render-processing-instruction',
	} );
	assert(
		'<?pi ?>\n\n' === decodeTree( processingInstruction ),
		'Empty processing-instruction data omitted its canonical separating space.'
	);

	const contextFixtures = [
		[ 'body', '<p>x', '<p>\n  "x"\n\n' ],
		[ 'div', '<p>x', '<p>\n  "x"\n\n' ],
		[ 'p', '<b>x', '<b>\n  "x"\n\n' ],
		[ 'td', '<b>x', '<b>\n  "x"\n\n' ],
		[ 'tr', '<td>x', '<td>\n  "x"\n\n' ],
		[ 'table', '<td>x', '<tbody>\n  <tr>\n    <td>\n      "x"\n\n' ],
		[ 'caption', '<b>x', '<b>\n  "x"\n\n' ],
		[ 'colgroup', '<col>', '<col>\n\n' ],
		[ 'select', '<option>x<option>y', '<option>\n  "x"\n<option>\n  "y"\n\n' ],
		[ 'option', 'x', '"x"\n\n' ],
		[ 'template', '<b>x', '<b>\n  "x"\n\n' ],
		[ 'title', '<b>&amp;', '"<b>&"\n\n' ],
		[ 'textarea', '<b>&amp;', '"<b>&"\n\n' ],
		[ 'script', '&amp;<b>', '"&amp;<b>"\n\n' ],
		[ 'style', '&amp;<b>', '"&amp;<b>"\n\n' ],
		[ 'svg', '<circle>', '<svg circle>\n\n' ],
		[ 'math', '<mi>x', '<math mi>\n  "x"\n\n' ],
	];
	assert(
		JSON.stringify( contexts ) === JSON.stringify( contextFixtures.map( ( fixture ) => fixture[ 0 ] ) ),
		'Exact context fixtures do not cover the ordered context registry.'
	);
	for ( const [ context, html, expectedTree ] of contextFixtures ) {
		const result = await request( service.socketPath, {
			id: 'context-' + context,
			command: 'render',
			mode: 'fragment-body',
			context,
			htmlBase64: Buffer.from( html ).toString( 'base64' ),
			maxNodes: 100,
			maxDepth: 32,
			maxTreeBytes: 65536,
		} );
		const actualTree = decodeTree( result );
		assert( expectedTree === actualTree, context + ' contextual fragment tree mismatch: ' + JSON.stringify( actualTree ) );
	}

	for ( const probe of [
		{ context: 'table', html: '<caption>x</caption><tbody><tr><td>y' },
		{ context: 'svg', html: '<g xlink:href=\"#x\"><title>s</title></g>' },
		{ context: 'math', html: '<mi>x</mi><annotation-xml encoding=\"text/html\"><b>y</b></annotation-xml>' },
		{ context: 'body', html: '<noscript><b>x</b></noscript>', noscript: true },
	] ) {
		const result = await request( service.socketPath, {
			command: 'render',
			mode: 'fragment-body',
			context: probe.context,
			htmlBase64: Buffer.from( probe.html ).toString( 'base64' ),
			maxNodes: 100,
			maxDepth: 32,
			maxTreeBytes: 65536,
		} );
		const tree = decodeTree( result );
		if ( probe.noscript ) {
			assert(
				'<noscript>\n  <b>\n    "x"\n\n' === tree,
				'Body-fragment noscript content was not parsed with scripting disabled.'
			);
		}
	}
	const adjustedSvg = await request( service.socketPath, {
		command: 'render',
		mode: 'fragment-body',
		context: 'svg',
		htmlBase64: Buffer.from(
			'<g viewbox="0 0 1 1" xlink:href="#x" xml:lang="en" xmlns:xlink="urn:x"/>'
		).toString( 'base64' ),
		maxNodes: 100,
		maxDepth: 32,
		maxTreeBytes: 65536,
	} );
	assert(
		'<svg g>\n  viewBox="0 0 1 1"\n  xlink href="#x"\n  xml lang="en"\n  xmlns xlink="urn:x"\n\n' === decodeTree( adjustedSvg ),
		'SVG context did not preserve namespace and attribute adjustment semantics.'
	);
	const headNoscript = await request( service.socketPath, {
		command: 'render',
		mode: 'full-document',
		htmlBase64: Buffer.from(
			'<!doctype html><html><head><noscript><meta name="x" content="y"></noscript></head><body>'
		).toString( 'base64' ),
		maxNodes: 100,
		maxDepth: 32,
		maxTreeBytes: 65536,
	} );
	assert(
		decodeTree( headNoscript ).includes(
			'<head>\n    <noscript>\n      <meta>\n        content="y"\n        name="x"\n'
		),
		'Head noscript content was not parsed with scripting disabled.'
	);

	let networkRequests = 0;
	const network = http.createServer( ( _request, response ) => {
		networkRequests++;
		response.end( 'unexpected' );
	} );
	network.listen( 0, '127.0.0.1' );
	await once( network, 'listening' );
	const port = network.address().port;
	const hostile = '<!doctype html><script>globalThis.__htmlApiFuzzAuthorRan=true</script>' +
		'<body onload=\"globalThis.__htmlApiFuzzAuthorRan=true\">' +
		'<img src=\"http://127.0.0.1:' + port + '/x\" onerror=\"globalThis.__htmlApiFuzzAuthorRan=true\">';
	const security = await request( service.socketPath, {
		command: 'render',
		mode: 'full-document',
		htmlBase64: Buffer.from( hostile ).toString( 'base64' ),
		maxNodes: 100,
		maxDepth: 32,
		maxTreeBytes: 65536,
		securityAudit: true,
	} );
	decodeTree( security );
	await delay( 200 );
	assert( false === security.securityAudit.authorRan, 'Author script or event handler executed.' );
	assert( ! security.securityAudit.activeMarkup.includes( '__htmlApiFuzzAuthorRan' ), 'Author markup entered the active renderer document.' );
	assert( 0 === security.securityAudit.resources.length, 'Active renderer document loaded a resource.' );
	assert( 0 === networkRequests, 'Hostile markup reached the network.' );
	await new Promise( ( resolve ) => network.close( resolve ) );
}

async function assertProtocolAndLimits( service ) {
	const invalidUtf8 = await request( service.socketPath, {
		command: 'render',
		mode: 'fragment-body',
		context: 'body',
		htmlBase64: Buffer.from( [ 0xc3, 0x28 ] ).toString( 'base64' ),
		maxNodes: 10,
		maxDepth: 10,
		maxTreeBytes: 1024,
	} );
	assert( 'unsupported' === invalidUtf8.status && 'invalid-utf8' === invalidUtf8.failureClass, 'Invalid UTF-8 was not structured unsupported.' );
	const maximumRawInput = {
		command: 'render',
		mode: 'fragment-body',
		context: 'body',
		htmlBase64: Buffer.alloc( 2 * 1024 * 1024, 0xff ).toString( 'base64' ),
		maxNodes: 10,
		maxDepth: 10,
		maxTreeBytes: 1024,
	};
	assert(
		Buffer.byteLength( JSON.stringify( maximumRawInput ) + '\n' ) <= MAX_REQUEST_FRAME_BYTES,
		'Maximum raw input did not fit the documented request-frame limit.'
	);
	const rawBoundary = await request( service.socketPath, maximumRawInput );
	assert(
		'unsupported' === rawBoundary.status && 'invalid-utf8' === rawBoundary.failureClass,
		'Exactly 2 MiB of raw input did not reach UTF-8 validation.'
	);
	const rawOverflow = await request( service.socketPath, {
		...maximumRawInput,
		htmlBase64: Buffer.alloc( 2 * 1024 * 1024 + 1, 0xff ).toString( 'base64' ),
	} );
	assert(
		'limit' === rawOverflow.status && 'input-byte-limit-exceeded' === rawOverflow.failureClass,
		'Raw input one byte above 2 MiB was not rejected before decoding.'
	);
	for ( const encoded of [ 'A', '====', 'YWJj=', 'YW Jj', 'YWJj\\n' ] ) {
		const invalid = await request( service.socketPath, {
			command: 'render',
			mode: 'fragment-body',
			context: 'body',
			htmlBase64: encoded,
		} );
		assert( 'protocol-error' === invalid.failureClass, 'Invalid base64 was accepted: ' + JSON.stringify( encoded ) );
	}
	const unknown = await request( service.socketPath, { command: 'wat' } );
	assert( 'protocol-error' === unknown.failureClass, 'Unknown command was not a protocol error.' );
	const extra = await request( service.socketPath, { command: 'version', extra: true } );
	assert( 'protocol-error' === extra.failureClass, 'Unknown version field was accepted.' );
	for ( const badRequest of [
		{ command: 'render', id: {}, htmlBase64: '', mode: 'fragment-body', context: 'body' },
		{ command: 'render', id: [], htmlBase64: '', mode: 'fragment-body', context: 'body' },
		{ command: 'render', htmlBase64: '', mode: '', context: 'body' },
		{ command: 'render', htmlBase64: '', mode: 'fragment-body', context: '' },
	] ) {
		const rejected = await request( service.socketPath, badRequest );
		assert( 'protocol-error' === rejected.failureClass, 'Malformed render request was defaulted or accepted.' );
	}
	const recovered = await request( service.socketPath, { command: 'version' } );
	assert( 'ok' === recovered.status, 'Server did not recover after bad requests.' );
	const maximumId = 'i'.repeat( 4096 );
	const maximumIdResult = await request( service.socketPath, { id: maximumId, command: 'version' } );
	assert( maximumId === maximumIdResult.id, 'Exact 4096-byte request id was not echoed.' );
	const oversizedId = await request( service.socketPath, { id: maximumId + 'i', command: 'version' } );
	assert(
		'protocol-error' === oversizedId.failureClass && ! Object.hasOwn( oversizedId, 'id' ),
		'Oversized string request id was accepted or echoed.'
	);

	const nodeOkay = await request( service.socketPath, {
		command: 'render', mode: 'fragment-body', context: 'body',
		htmlBase64: Buffer.from( '<a></a>' ).toString( 'base64' ),
		maxNodes: 1, maxDepth: 1, maxTreeBytes: 1024,
	} );
	decodeTree( nodeOkay );
	const nodeLimited = await request( service.socketPath, {
		command: 'render', mode: 'fragment-body', context: 'body',
		htmlBase64: Buffer.from( '<a>x</a>' ).toString( 'base64' ),
		maxNodes: 1, maxDepth: 2, maxTreeBytes: 1024,
	} );
	assert(
		'limit' === nodeLimited.status &&
		'node-limit-exceeded' === nodeLimited.failureClass &&
		2 === nodeLimited.nodeCount,
		'Node limit failed or did not report maxNodes + 1.'
	);

	const depthOkay = await request( service.socketPath, {
		command: 'render', mode: 'fragment-body', context: 'body',
		htmlBase64: Buffer.from( '<a><b><c></c></b></a>' ).toString( 'base64' ),
		maxNodes: 3, maxDepth: 2, maxTreeBytes: 1024,
	} );
	decodeTree( depthOkay );
	const depthLimited = await request( service.socketPath, {
		command: 'render', mode: 'fragment-body', context: 'body',
		htmlBase64: Buffer.from( '<a><b><c></c></b></a>' ).toString( 'base64' ),
		maxNodes: 3, maxDepth: 1, maxTreeBytes: 1024,
	} );
	assert( 'limit' === depthLimited.status && 'depth-limit-exceeded' === depthLimited.failureClass, 'Depth limit failed.' );

	const byteProbe = await request( service.socketPath, {
		command: 'render', mode: 'fragment-body', context: 'body',
		htmlBase64: Buffer.from( '<a>xy</a>' ).toString( 'base64' ),
		maxNodes: 2, maxDepth: 2, maxTreeBytes: 1024,
	} );
	decodeTree( byteProbe );
	const byteExact = await request( service.socketPath, {
		command: 'render', mode: 'fragment-body', context: 'body',
		htmlBase64: Buffer.from( '<a>xy</a>' ).toString( 'base64' ),
		maxNodes: 2, maxDepth: 2, maxTreeBytes: byteProbe.treeBytes,
	} );
	decodeTree( byteExact );
	const byteLimited = await request( service.socketPath, {
		command: 'render', mode: 'fragment-body', context: 'body',
		htmlBase64: Buffer.from( '<a>xy</a>' ).toString( 'base64' ),
		maxNodes: 2, maxDepth: 2, maxTreeBytes: byteProbe.treeBytes - 1,
	} );
	assert( 'limit' === byteLimited.status && 'tree-byte-limit-exceeded' === byteLimited.failureClass, 'Tree byte limit failed.' );

	const depth = 1000;
	const siblings = 7869;
	const filler = 736;
	const exactHtml = '<div>'.repeat( depth ) + '<i></i>'.repeat( siblings ) + 'x'.repeat( filler ) + '</div>'.repeat( depth );
	assert( Buffer.byteLength( exactHtml ) < 2 * 1024 * 1024, 'Exact-boundary fixture exceeded input limit.' );
	const boundaryProbe = await request( service.socketPath, {
		command: 'render',
		mode: 'fragment-body',
		context: 'body',
		htmlBase64: Buffer.from( exactHtml ).toString( 'base64' ),
		maxNodes: 20000,
		maxDepth: 1024,
		maxTreeBytes: MAX_TREE_BYTES,
	}, 60000 );
	decodeTree( boundaryProbe );
	assert( boundaryProbe.treeBytes < MAX_TREE_BYTES, 'Hard-boundary probe unexpectedly reached its limit.' );
	const probeTree = Buffer.from( boundaryProbe.treeBase64, 'base64' );
	const marker = Buffer.from( '<i>\n' );
	const markerIndex = probeTree.indexOf( marker );
	const lineStart = probeTree.lastIndexOf( 0x0a, markerIndex - 1 ) + 1;
	const siblingBytes = markerIndex + marker.length - lineStart;
	assert( siblingBytes > 4, 'Could not measure deepest sibling indentation.' );
	const needed = MAX_TREE_BYTES - boundaryProbe.treeBytes;
	const extraSiblings = Math.floor( needed / siblingBytes );
	const extraFiller = needed - extraSiblings * siblingBytes;
	const exactAdjustedHtml = '<div>'.repeat( depth ) + '<i></i>'.repeat( siblings + extraSiblings ) +
		'x'.repeat( filler + extraFiller ) + '</div>'.repeat( depth );
	assert( Buffer.byteLength( exactAdjustedHtml ) < 2 * 1024 * 1024, 'Adjusted hard-boundary fixture exceeded input limit.' );
	const exactMaximum = await request( service.socketPath, {
		command: 'render',
		mode: 'fragment-body',
		context: 'body',
		htmlBase64: Buffer.from( exactAdjustedHtml ).toString( 'base64' ),
		maxNodes: 20000,
		maxDepth: 1024,
		maxTreeBytes: MAX_TREE_BYTES,
	}, 60000 );
	decodeTree( exactMaximum );
	assert( MAX_TREE_BYTES === exactMaximum.treeBytes, 'Adjusted fixture did not produce exactly 16 MiB.' );
	const responseBytes = Buffer.byteLength( JSON.stringify( exactMaximum ) + '\n' );
	assert( responseBytes <= MAX_RESPONSE_FRAME_BYTES, 'Exact 16 MiB success did not fit its 24 MiB frame.' );
	assert(
		Buffer.byteLength( JSON.stringify( { ...exactMaximum, id: 'i'.repeat( 4096 ) } ) + '\n' ) <= MAX_RESPONSE_FRAME_BYTES,
		'Exact 16 MiB success plus maximum request id did not fit its 24 MiB frame.'
	);
	const belowMaximum = await request( service.socketPath, {
		command: 'render',
		mode: 'fragment-body',
		context: 'body',
		htmlBase64: Buffer.from( exactAdjustedHtml ).toString( 'base64' ),
		maxNodes: 20000,
		maxDepth: 1024,
		maxTreeBytes: MAX_TREE_BYTES - 1,
	}, 60000 );
	assert( 'limit' === belowMaximum.status, 'One-byte-below hard boundary did not limit.' );
}

async function assertFramingAndClients( service, temporaryDirectory ) {
	const twoFrames = await new Promise( ( resolve, reject ) => {
		const socket = net.createConnection( service.socketPath );
		let received = '';
		const timer = setTimeout( () => {
			socket.destroy();
			reject( new Error( 'Two-frame socket did not close after one response.' ) );
		}, 10000 );
		socket.once( 'error', reject );
		socket.on( 'data', ( chunk ) => {
			received += chunk.toString( 'utf8' );
		} );
		socket.once( 'end', () => {
			clearTimeout( timer );
			resolve( received.split( '\n' ).filter( Boolean ).map( JSON.parse ) );
		} );
		socket.once( 'connect', () => {
			socket.write(
				JSON.stringify( { id: 'first', command: 'version' } ) + '\n' +
				JSON.stringify( { id: 'second', command: 'version' } ) + '\n'
			);
		} );
	} );
	assert( 1 === twoFrames.length && 'first' === twoFrames[ 0 ].id, 'Socket connection processed more than its first frame.' );
	const malformedThenValid = await new Promise( ( resolve, reject ) => {
		const socket = net.createConnection( service.socketPath );
		let received = '';
		const timer = setTimeout( () => {
			socket.destroy();
			reject( new Error( 'Malformed-first socket did not close after one response.' ) );
		}, 10000 );
		socket.once( 'error', reject );
		socket.on( 'data', ( chunk ) => {
			received += chunk.toString( 'utf8' );
		} );
		socket.once( 'end', () => {
			clearTimeout( timer );
			resolve( received.split( '\n' ).filter( Boolean ).map( JSON.parse ) );
		} );
		socket.once( 'connect', () => {
			socket.write( '{bad json}\n' + JSON.stringify( { command: 'version' } ) + '\n' );
		} );
	} );
	assert(
		1 === malformedThenValid.length && 'protocol-error' === malformedThenValid[ 0 ].failureClass,
		'Malformed first frame did not consume and close its socket exactly once.'
	);
	const malformedUtf8 = await requestRaw(
		service.socketPath,
		Buffer.concat( [
			Buffer.from( '{"id":"', 'utf8' ),
			Buffer.from( [ 0xff ] ),
			Buffer.from( '","command":"version"}\n', 'utf8' ),
		] )
	);
	assert(
		'protocol-error' === malformedUtf8.failureClass && ! Object.hasOwn( malformedUtf8, 'id' ),
		'Malformed UTF-8 frame was accepted or echoed a replacement request id.'
	);
	for ( const frame of [
		'{"id":1e309,"command":"version"}\n',
		'{"id":9007199254740993,"command":"version"}\n',
	] ) {
		const invalidId = await requestRaw( service.socketPath, Buffer.from( frame, 'utf8' ) );
		assert(
			'protocol-error' === invalidId.failureClass && ! Object.hasOwn( invalidId, 'id' ),
			'Unsafe numeric request id was accepted or echoed after mutation.'
		);
	}

	const oversized = await requestRaw(
		service.socketPath,
		Buffer.concat( [ Buffer.alloc( MAX_REQUEST_FRAME_BYTES + 1, 0x78 ), Buffer.from( '\n' ) ] )
	);
	assert( 'protocol-error' === oversized.failureClass, 'Oversized socket frame was not rejected.' );
	const recovered = await request( service.socketPath, { command: 'version' } );
	assert( 'ok' === recovered.status, 'Service did not recover after oversized socket client.' );

	const pressureHeld = [];
	const pressureHeldClosed = [];
	for ( let index = 0; index < 7; index++ ) {
		const socket = net.createConnection( service.socketPath );
		await once( socket, 'connect' );
		pressureHeld.push( socket );
		pressureHeldClosed.push( once( socket, 'close' ) );
		socket.write( '{"command"' );
	}
	const pressured = net.createConnection( service.socketPath );
	pressured.pause();
	await once( pressured, 'connect' );
	const pressuredClosed = once( pressured, 'close' );
	pressured.write( JSON.stringify( { command: 'test-large-response' } ) + '\n' );
	await delay( 1000 );
	const pressureNinth = await request( service.socketPath, { command: 'version' } );
	assert(
		'protocol-error' === pressureNinth.failureClass && pressureNinth.error.includes( 'client limit' ),
		'Backpressured eighth client did not continue to occupy its admission slot.'
	);
	const pressureReleaseStarted = Date.now();
	pressured.destroy();
	await pressuredClosed;
	await delay( 100 );
	const pressureProgress = await request( service.socketPath, { command: 'version' }, 5000 );
	assert( 'ok' === pressureProgress.status, 'Dispatcher did not progress after a backpressured client disconnected.' );
	assert( Date.now() - pressureReleaseStarted < 5000, 'Backpressured client did not settle within its close bound.' );
	for ( const socket of pressureHeld ) {
		socket.destroy();
	}
	await Promise.all( pressureHeldClosed );
	assert( null === service.child.exitCode, 'Backpressured client disconnect terminated the service.' );

	const heldClosed = [];
	for ( let index = 0; index < 8; index++ ) {
		const socket = net.createConnection( service.socketPath );
		await once( socket, 'connect' );
		heldClosed.push( once( socket, 'close' ) );
		socket.write( '{"command"' );
	}
	await delay( 100 );
	const droppedNinth = net.createConnection( service.socketPath );
	await once( droppedNinth, 'connect' );
	const droppedNinthClosed = once( droppedNinth, 'close' );
	droppedNinth.destroy();
	await droppedNinthClosed;
	await delay( 50 );
	assert( null === service.child.exitCode, 'Disconnected rejected client crashed the socket service.' );
	const ninth = await request( service.socketPath, { command: 'version' } );
	assert( 'protocol-error' === ninth.failureClass && ninth.error.includes( 'client limit' ), 'Ninth socket client was accepted.' );
	await Promise.race( [
		Promise.all( heldClosed ),
		delay( 15000 ).then( () => { throw new Error( 'Partial-frame socket clients outlived their read deadline.' ); } ),
	] );
	const afterIdleClients = await request( service.socketPath, { command: 'version' } );
	assert( 'ok' === afterIdleClients.status, 'Idle socket clients permanently exhausted admission slots.' );

	const stdio = spawn( process.execPath, [ SCRIPT, '--serve' ], {
		stdio: [ 'pipe', 'pipe', 'pipe' ],
		env: { ...process.env, HTML_API_FUZZ_CHROME_TEST_ALLOW_INTERNAL_COMMANDS: '1' },
	} );
	let stdout = '';
	let stderr = '';
	const responses = [];
	stdio.stdout.on( 'data', ( chunk ) => {
		stdout += chunk.toString( 'utf8' );
		for ( ;; ) {
			const newline = stdout.indexOf( '\n' );
			if ( newline < 0 ) {
				break;
			}
			responses.push( JSON.parse( stdout.slice( 0, newline ) ) );
			stdout = stdout.slice( newline + 1 );
		}
	} );
	stdio.stderr.on( 'data', ( chunk ) => {
		stderr = ( stderr + chunk.toString( 'utf8' ) ).slice( -65536 );
	} );
	stdio.stdin.write( Buffer.alloc( MAX_REQUEST_FRAME_BYTES + 1, 0x78 ) );
	stdio.stdin.write( '\n' + JSON.stringify( { id: 2, command: 'version' } ) + '\n' );
	const deadline = Date.now() + 30000;
	while ( responses.length < 2 && Date.now() < deadline ) {
		await delay( 25 );
	}
	assert( responses.length >= 2, 'Stdio framing recovery timed out. ' + stderr );
	assert( 'protocol-error' === responses[ 0 ].failureClass, 'Oversized stdio frame was not rejected.' );
	assert( 'ok' === responses[ 1 ].status && 2 === responses[ 1 ].id, 'Stdio did not recover through newline.' );
	stdio.stdin.write( JSON.stringify( { command: 'shutdown' } ) + '\n' );
	const outcome = await waitForExit( stdio, 20000 );
	assert( 0 === outcome.code, 'Stdio service did not shut down after framing test. ' + stderr );
}

async function assertWarmReuseAndRestart( service ) {
	const first = await request( service.socketPath, { command: 'version' } );
	const firstTransport = first.oracle.transport;
	const pid = firstTransport.browserPid;
	const concurrent = await Promise.all(
		[ 1, 2, 3, 4 ].map( ( id ) => request( service.socketPath, {
			id,
			command: 'render',
			mode: 'fragment-body',
			context: 'body',
			htmlBase64: Buffer.from( '<p>' + id ).toString( 'base64' ),
			maxNodes: 10,
			maxDepth: 10,
			maxTreeBytes: 4096,
		} ) )
	);
	for ( const result of concurrent ) {
		decodeTree( result );
		assert( pid === result.oracle.transport.browserPid, 'Concurrent request did not reuse warm Chrome.' );
	}
	const oldProcesses = captureDescendantIdentities( firstTransport.supervisorPid );
	const killed = await request( service.socketPath, { command: 'test-kill-browser' } );
	assert( 'ok' === killed.status, 'Could not terminate browser for restart test.' );
	await delay( 500 );
	const restarted = await request( service.socketPath, {
		command: 'render',
		mode: 'fragment-body',
		context: 'body',
		htmlBase64: Buffer.from( '<p>restart' ).toString( 'base64' ),
		maxNodes: 10,
		maxDepth: 10,
		maxTreeBytes: 4096,
	} );
	decodeTree( restarted );
	assert( pid !== restarted.oracle.transport.browserPid, 'Ordinary browser death did not produce one clean restart.' );
	assert( firstTransport.supervisorPid !== restarted.oracle.transport.supervisorPid, 'Ordinary browser death reused its old supervisor.' );
	assert( firstTransport.runtimeRoot !== restarted.oracle.transport.runtimeRoot, 'Ordinary browser death reused its old runtime.' );
	await waitForIdentitiesGone( oldProcesses );
	await waitForPathGone( firstTransport.profilePath );
	await waitForPathGone( firstTransport.runtimeRoot );
}

async function assertLifecycleCleanup( temporaryDirectory, target, phase ) {
	const pauseFile = path.join( temporaryDirectory, 'pause-' + target + '-' + phase + '-' + crypto.randomBytes( 3 ).toString( 'hex' ) );
	const environment = 'pre-browser' === phase
		? { HTML_API_FUZZ_CHROME_TEST_PAUSE_AFTER_SUPERVISOR_SPAWN: pauseFile }
		: 'browser-startup' === phase
			? { HTML_API_FUZZ_CHROME_TEST_PAUSE_AFTER_BROWSER_SPAWN: pauseFile }
			: 'cdp-handshake' === phase
				? { HTML_API_FUZZ_CHROME_TEST_PAUSE_AFTER_CDP_HANDSHAKE: pauseFile }
				: {};
	const service = spawnService( temporaryDirectory, environment );
	let metadata;
	if ( 'steady' !== phase ) {
		const deadline = Date.now() + 35000;
		while ( ! fs.existsSync( pauseFile ) && Date.now() < deadline ) {
			if ( null !== service.child.exitCode ) {
				throw new Error(
					'Lifecycle startup service exited before pause.\nstdout: ' + service.stdout +
					'\nstderr: ' + service.stderr
				);
			}
			await delay( 20 );
		}
		if ( ! fs.existsSync( pauseFile ) ) {
			service.child.kill( 'SIGKILL' );
			await waitForExit( service.child, 5000 ).catch( () => {} );
			throw new Error(
				'Lifecycle startup pause was not reached.\nstdout: ' + service.stdout +
				'\nstderr: ' + service.stderr
			);
		}
		metadata = JSON.parse( fs.readFileSync( pauseFile, 'utf8' ) );
		assert( ! fs.existsSync( metadata.socketPath ), 'Startup exposed its socket before browser authentication.' );
		assert(
			! fs.existsSync( path.join( __dirname, '.chrome-for-testing', '.install.lock' ) ),
			'Startup authentication left an installer lock.'
		);
	} else {
		const ready = await waitForReady( service.child );
		metadata = {
			ownerPid: ready.oracle.transport.ownerPid,
			supervisorPid: ready.oracle.transport.supervisorPid,
			browserPid: ready.oracle.transport.browserPid,
			runtimeRoot: ready.oracle.transport.runtimeRoot,
			profilePath: ready.oracle.transport.profilePath,
			socketPath: ready.oracle.transport.socketPath,
		};
	}
	const capturedProcesses = captureDescendantIdentities( metadata.supervisorPid );
	if ( 'pre-browser' === phase ) {
		assert(
			1 === capturedProcesses.length && capturedProcesses[ 0 ].pid === metadata.supervisorPid,
			'Pre-browser pause occurred after the supervisor launched an executable child.'
		);
	} else if ( 'browser-startup' === phase || 'cdp-handshake' === phase ) {
		assert(
			capturedProcesses.some( ( processRecord ) => processRecord.pid === metadata.browserPid ) &&
			capturedProcesses.length > 1,
			'Browser startup pause did not capture the token-bearing Chrome tree.'
		);
	}

	if ( 'owner' === target ) {
		process.kill( metadata.ownerPid, 'SIGKILL' );
	} else if ( 'browser' === target ) {
		process.kill( metadata.browserPid, 'SIGKILL' );
	} else {
		process.kill( metadata.supervisorPid, 'SIGKILL' );
	}
	if ( fs.existsSync( pauseFile ) ) {
		fs.unlinkSync( pauseFile );
	}
	let outcome;
	try {
		outcome = await waitForExit( service.child, 20000 );
	} catch ( error ) {
		service.child.kill( 'SIGKILL' );
		await waitForExit( service.child, 5000 ).catch( () => {} );
		error.message += '\nstdout: ' + service.stdout + '\nstderr: ' + service.stderr;
		throw error;
	}
	if ( 'owner' === target ) {
		assert( 'SIGKILL' === outcome.signal, 'Owner hard-kill did not terminate by SIGKILL.' );
	} else {
		assert( 0 !== outcome.code, target + ' hard-kill did not fail the owner service.' );
	}
	await waitForPidGone( metadata.supervisorPid );
	if ( metadata.browserPid ) {
		await waitForPidGone( metadata.browserPid );
	}
	await waitForIdentitiesGone( capturedProcesses );
	await waitForNoOwnedProcesses( metadata.profilePath );
	await waitForPathGone( metadata.runtimeRoot );
	await waitForPathGone( metadata.socketPath );
	const lockDirectory = path.join( __dirname, '.chrome-for-testing', '.install.lock' );
	await waitForPathGone( lockDirectory );
}

async function assertSignalCleanup( temporaryDirectory ) {
	const service = spawnService( temporaryDirectory );
	const ready = await waitForReady( service.child );
	const transport = ready.oracle.transport;
	const capturedProcesses = captureDescendantIdentities( transport.supervisorPid );
	service.child.kill( 'SIGTERM' );
	const outcome = await waitForExit( service.child, 20000 );
	assert( 0 === outcome.code, 'SIGTERM cleanup did not exit cleanly.' );
	await waitForPidGone( transport.supervisorPid );
	await waitForPidGone( transport.browserPid );
	await waitForIdentitiesGone( capturedProcesses );
	await waitForNoOwnedProcesses( transport.profilePath );
	await waitForPathGone( transport.runtimeRoot );
	await waitForPathGone( transport.socketPath );
}

async function main() {
	const temporaryDirectory = fs.mkdtempSync( path.join( os.tmpdir(), 'html-api-fuzz-chrome-smoke-' ) );
	fs.chmodSync( temporaryDirectory, 0o700 );
	const chromeExecutable = executablePath();
	assert( fs.existsSync( chromeExecutable ), 'Pinned Chrome is not installed.' );
	const contexts = assertContextDrift();
	assertStrictCli( temporaryDirectory );
	await assertOneShotInputSnapshot( temporaryDirectory );
	await assertRealStdoutWriteDeadline();
	assertRuntimeManifestStrictness( temporaryDirectory );
	assertCdpWebSocketProtocol();
	assertSupervisorBackpressure();
	await assertSupervisorProtocolValidation();
	assertSnapshotRetentionBound();
	await assertBoundedPeerAndCleanupInfrastructure( temporaryDirectory );
	await assertCdpEnvelopeValidation();
	await assertTimeoutDoesNotReplay();
	await assertRendererOutputValidation();

	const service = spawnService( temporaryDirectory );
	try {
		const ready = await waitForReady( service.child );
		assertIdentity( ready, chromeExecutable );
		const socketMode = fs.statSync( service.socketPath ).mode & 0o777;
		assert( 0o600 === socketMode, 'Oracle socket mode was not 0600.' );
		await assertCanonicalAndSecurity( service, contexts );
		await assertProtocolAndLimits( service );
		await assertFramingAndClients( service, temporaryDirectory );
		await assertWarmReuseAndRestart( service );
		await shutdownService( service );
		await waitForPathGone( ready.oracle.transport.runtimeRoot );
		await waitForPidGone( ready.oracle.transport.supervisorPid );
	} finally {
		if ( null === service.child.exitCode && null === service.child.signalCode ) {
			service.child.kill( 'SIGTERM' );
			await waitForExit( service.child ).catch( () => {} );
		}
	}

	await assertSignalCleanup( temporaryDirectory );
	for ( const target of [ 'owner', 'supervisor' ] ) {
		for ( const phase of [ 'pre-browser', 'browser-startup', 'steady' ] ) {
			try {
				await assertLifecycleCleanup( temporaryDirectory, target, phase );
			} catch ( error ) {
				error.message = target + '/' + phase + ': ' + error.message;
				throw error;
			}
		}
	}
	for ( const target of [ 'supervisor', 'browser' ] ) {
		try {
			await assertLifecycleCleanup( temporaryDirectory, target, 'cdp-handshake' );
		} catch ( error ) {
			error.message = target + '/cdp-handshake: ' + error.message;
			throw error;
		}
	}

	fs.rmSync( temporaryDirectory, { recursive: true, force: true } );
	process.stdout.write( 'chrome direct-CDP smoke: ok\n' );
}

main().catch( ( error ) => {
	process.stderr.write( ( error.stack || error.message || String( error ) ) + '\n' );
	process.exitCode = 1;
} );
